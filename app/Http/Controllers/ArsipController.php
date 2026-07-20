<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DokumenCetak;
use App\Models\Template;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Throwable;

class ArsipController extends Controller
{
    /**
     * 1. Mengambil data khusus untuk halaman Arsip & History (Hanya yang sudah bertanda tangan / selesai)
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user(); // 🔐 Ambil data user yang sedang login
            
            // ✨ Mengambil dokumen yang sudah di-upload foto TTD atau yang diselesaikan langsung ('tanpa_ttd')
            $query = DokumenCetak::whereNotNull('foto_ttd')
                ->where('foto_ttd', '!=', '')
                ->latest();

            // 🔒 FILTER PRIVASI: Mengunci data milik sendiri atau data yang menunjuk dia sebagai penanggung jawab
            if ($user) {
                $query->where(function($q) use ($user) {
                    // Alur 1: Wajib miliknya sendiri berdasarkan ID
                    $q->where('user_id', $user->id);
                    
                    // Alur 2: ATAU namanya disebut spesifik di kolom variables JSON (Kebal huruf besar/kecil)
                    $identity = $user->name ?? $user->username ?? $user->nama ?? null;
                    if (!empty($identity)) {
                        $q->orWhereRaw("LOWER(variables) LIKE ?", ['%"nama_penanggung":"' . strtolower($identity) . '"%']);
                    }
                });
            }

            // ✨ VERSI STATUS_AKTIF: Membaca parameter ?status_aktif= dari Frontend (default: 1)
            $statusAktif = $request->query('status_aktif', 1);
            $query->where('status_aktif', $statusAktif);

            $data = $query->get()->map(function($dokumen) {
                $template = Template::find($dokumen->template_id);
                $namaTemplate = $template ? $template->nama : 'Dokumen';

                $vars = $dokumen->variables;
                if (is_string($vars)) {
                    $vars = json_decode($vars, true);
                } else {
                    $vars = (array)$vars;
                }
                $noUrut = $vars['no'] ?? '000';
                $tahun = $dokumen->created_at ? $dokumen->created_at->format('Y') : date('Y');
                $bulanAngka = $dokumen->created_at ? (int)$dokumen->created_at->format('n') : (int)date('n');
                $romawiArr = [1=>'I', 2=>'II', 3=>'III', 4=>'IV', 5=>'V', 6=>'VI', 7=>'VII', 8=>'VIII', 9=>'IX', 10=>'X', 11=>'XI', 12=>'XII'];
                $bulanRomawi = $romawiArr[$bulanAngka] ?? 'I';

                $namaOtomatis = "{$namaTemplate} - {$noUrut}/BTG-{$bulanRomawi}/BA/{$tahun}";
                
                $dokumen->nama_dokumen = $namaOtomatis;
                $dokumen->nama_arsip_display = $namaOtomatis; 

                $dokumen->items = DB::table('dokumen_item')
                    ->where('dokumen_id', $dokumen->id)
                    ->get();

                return $dokumen;
            });
            
            return response()->json($data, 200);
            
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 2. PINDAHAN: Mengunggah foto tanda tangan bukti penyerahan fisik + join ke tabel utama
     */
    public function uploadBukti(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'foto_ttd' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $karyawanId = DB::table('karyawan')->value('id');

        if (!$karyawanId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal Simpan! Tabel [karyawan] kamu masih kosong.'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $dokumenCetak = DokumenCetak::findOrFail($id);

            if ($request->hasFile('foto_ttd')) {
                $file = $request->file('foto_ttd');
                $nameFile = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $subFolder = 'uploads/bukti_ttd';
                $file->move(public_path($subFolder), $nameFile);
                $pathFotoFinal = $subFolder . '/' . $nameFile;

                if ($dokumenCetak->foto_ttd && File::exists(public_path($dokumenCetak->foto_ttd))) {
                    File::delete(public_path($dokumenCetak->foto_ttd));
                }

                // Update foto_ttd saja tanpa kolom status
                $dokumenCetak->update([
                    'foto_ttd' => $pathFotoFinal,
                ]);

                $template = Template::find($dokumenCetak->template_id);
                $namaTemplate = $template ? $template->nama : 'k';

                $vars = $dokumenCetak->variables;
                if (is_string($vars)) {
                    $vars = json_decode($vars, true);
                } else {
                    $vars = (array)$vars;
                }
                $noUrut = $vars['no'] ?? '000';
                $tahun = $dokumenCetak->created_at ? $dokumenCetak->created_at->format('Y') : date('Y');
                $bulanAngka = $dokumenCetak->created_at ? (int)$dokumenCetak->created_at->format('n') : (int)date('n');
                
                $romawiArr = [1=>'I', 2=>'II', 3=>'III', 4=>'IV', 5=>'V', 6=>'VI', 7=>'VII', 8=>'VIII', 9=>'IX', 10=>'X', 11=>'XI', 12=>'XII'];
                $bulanRomawi = $romawiArr[$bulanAngka] ?? 'I';

                $namaOtomatis = "{$namaTemplate} - {$noUrut}/BTG-{$bulanRomawi}/BA/{$tahun}";

                $isSuratTugas = $dokumenCetak->template_id == 3 || str_contains(strtolower($namaTemplate), 'surat tugas');

                $dataInsert = [
                    'template_id'      => $dokumenCetak->template_id,
                    'user_id'          => auth()->id() ?? 3, 
                    'jenis_dokumen_id' => $template->jenis_dokumen_id ?? 1, 
                    'nomor_dokumen'    => $namaOtomatis,                  
                    'status'           => 'arsip',           
                    'tanggal_dokumen'  => $dokumenCetak->created_at ?? now(),
                    'penyerah_id'      => $karyawanId,                 
                    'penerima_id'      => $karyawanId,                 
                    'opsi_foto'        => 1,                 
                    'kolom_tambahan'   => json_encode($vars),             
                    'file_sblm_print'  => $dokumenCetak->file_path,
                    'file_sesudah_ttd' => $pathFotoFinal,                                               
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ];

                if ($isSuratTugas) {
                    $dataInsert['jenis'] = 'surat_tugas';
                    DB::table('dokumen')->insert($dataInsert);
                } else {
                    $jenisEnum = 'penyerahan';
                    if (str_contains(strtolower($namaTemplate), 'kerusakan') || strtolower($namaTemplate) == 'BA Kerusakan') {
                        $jenisEnum = 'kerusakan';
                    }
                    $dataInsert['jenis']   = $jenisEnum;
                    DB::table('dokumen')->insert($dataInsert);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success', 
                'message' => 'Hore! Data berhasil masuk ke tabel dokumen!',
                'path' => $pathFotoFinal ?? null
            ], 200);
            
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ✨ 3. FITUR BARU: Selesai langsung khusus Surat Tugas (Tanpa upload foto) ✨
     */
    public function selesaiSuratTugas($id)
    {
        $karyawanId = DB::table('karyawan')->value('id');

        if (!$karyawanId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal Simpan! Tabel [karyawan] kamu masih kosong.'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $dokumenCetak = DokumenCetak::findOrFail($id);
            $template = Template::find($dokumenCetak->template_id);
            $namaTemplate = $template ? $template->nama : 'Surat Tugas';

            // Validasi pengaman: Pastikan memang benar ini dokumen Surat Tugas
            $isSuratTugas = $dokumenCetak->template_id == 3 || str_contains(strtolower($namaTemplate), 'surat tugas');
            if (!$isSuratTugas) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Gagal! Tombol selesai langsung hanya berlaku untuk dokumen Surat Tugas.'
                ], 400);
            }

            // Gunakan string penanda khusus 'tanpa_ttd' agar lolos validasi list Arsip
            $pathFotoFinal = 'tanpa_ttd';

            // Update status foto_ttd di tabel dokumen_cetak
            $dokumenCetak->update([
                'foto_ttd' => $pathFotoFinal,
            ]);

            // Buat penamaan otomatis nomor dokumen
            $vars = $dokumenCetak->variables;
            if (is_string($vars)) {
                $vars = json_decode($vars, true);
            } else {
                $vars = (array)$vars;
            }
            $noUrut = $vars['no'] ?? '000';
            $tahun = $dokumenCetak->created_at ? $dokumenCetak->created_at->format('Y') : date('Y');
            $bulanAngka = $dokumenCetak->created_at ? (int)$dokumenCetak->created_at->format('n') : (int)date('n');
            
            $romawiArr = [1=>'I', 2=>'II', 3=>'III', 4=>'IV', 5=>'V', 6=>'VI', 7=>'VII', 8=>'VIII', 9=>'IX', 10=>'X', 11=>'XI', 12=>'XII'];
            $bulanRomawi = $romawiArr[$bulanAngka] ?? 'I';

            $namaOtomatis = "{$namaTemplate} - {$noUrut}/BTG-{$bulanRomawi}/BA/{$tahun}";

            // Daftarkan langsung ke tabel dokumen (Arsip Utama)
            $dataInsert = [
                'template_id'      => $dokumenCetak->template_id,
                'user_id'          => auth()->id() ?? 3, 
                'jenis_dokumen_id' => $template->jenis_dokumen_id ?? 1, 
                'nomor_dokumen'    => $namaOtomatis,                  
                'status'           => 'arsip',           
                'tanggal_dokumen'  => $dokumenCetak->created_at ?? now(),
                'penyerah_id'      => $karyawanId,                 
                'penerima_id'      => $karyawanId,                 
                'opsi_foto'        => 0, // 0 = Tanpa Bukti Foto Fisik                 
                'kolom_tambahan'   => json_encode($vars),             
                'file_sblm_print'  => $dokumenCetak->file_path,
                'file_sesudah_ttd' => $pathFotoFinal, // Menyimpan teks 'tanpa_ttd'                                               
                'jenis'            => 'surat_tugas',
                'created_at'       => now(),
                'updated_at'       => now(),
            ];

            DB::table('dokumen')->insert($dataInsert);

            DB::commit();

            return response()->json([
                'status' => 'success', 
                'message' => 'Hore! Surat Tugas berhasil diselesaikan dan diarsipkan!'
            ], 200);
            
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Toggle Aktif / Nonaktif dokumen
     */
    public function toggleAktif($id)
    {
        try {
            $dokumen = DokumenCetak::findOrFail($id);
            $dokumen->status_aktif = $dokumen->status_aktif == 1 ? 0 : 1;
            $dokumen->save();

            return response()->json([
                'status' => 'success',
                'message' => $dokumen->status_aktif == 1 ? 'Dokumen berhasil diaktifkan!' : 'Dokumen berhasil dinonaktifkan!',
                'status_aktif' => $dokumen->status_aktif
            ], 200);
            
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}