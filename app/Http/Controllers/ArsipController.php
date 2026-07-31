<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DokumenCetak;
use App\Models\Template;
use App\Services\DocumentGeneratorService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Throwable;

class ArsipController extends Controller
{
    protected $documentService;

    public function __construct(DocumentGeneratorService $documentService)
    {
        $this->documentService = $documentService;
    }

    /**
     * 1. Menampilkan Daftar Dokumen Arsip
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user(); 
            
            $query = DokumenCetak::whereNotNull('foto_ttd')
                ->where('foto_ttd', '!=', '')
                ->latest();

            if ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id);
                    $identity = $user->name ?? $user->username ?? $user->nama ?? null;
                    if (!empty($identity)) {
                        $q->orWhereRaw("LOWER(variables) LIKE ?", ['%"nama_penanggung":"' . strtolower($identity) . '"%']);
                    }
                });
            }

            $statusAktif = $request->query('status_aktif', 1);
            $query->where('status_aktif', $statusAktif);

            $dokumenList = $query->get();

            $dokumenIds = $dokumenList->pluck('id');
            $allItems = DB::table('dokumen_item')
                ->whereIn('dokumen_id', $dokumenIds)
                ->get()
                ->groupBy('dokumen_id');

            $data = $dokumenList->map(function($dokumen) use ($allItems) {
                $dokumen->nama_arsip_display = $dokumen->nama_dokumen; 
                $dokumen->items = $allItems->get($dokumen->id, []);
                return $dokumen;
            });
            
            return response()->json($data, 200);
            
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 2. Upload Bukti TTD (Cetak Fisik Selesai via Upload)
     */
    public function uploadBukti(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'foto_ttd' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
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

                // 🔑 Update tanggal_cetak di dokumen_cetak jika masih kosong
                if (empty($dokumenCetak->tanggal_cetak)) {
                    $dokumenCetak->tanggal_cetak = now();
                }

                $dokumenCetak->foto_ttd = $pathFotoFinal;
                $dokumenCetak->save(); // Simpan tanggal_cetak & foto_ttd ke DB

                // Pindahkan & simpan tanggal_cetak ke tabel induk 'dokumen'
                $this->simpanKeMasterDokumen($dokumenCetak, $pathFotoFinal, 1, false);
            }

            DB::commit();

            return response()->json([
                'status' => 'success', 
                'message' => 'Hore! Bukti TTD berhasil diupload dan data masuk ke master dokumen!',
                'path' => $pathFotoFinal ?? null,
                'tanggal_cetak' => $dokumenCetak->tanggal_cetak
            ], 200);
            
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 3. Selesai Direct (Khusus Surat Tugas & TOR)
     */
    public function selesaiSuratTugas($id)
    {
        DB::beginTransaction();

        try {
            $dokumenCetak = DokumenCetak::findOrFail($id);
            $namaDokumenFinal = strtolower($dokumenCetak->nama_dokumen ?? '');

            // Pengecekan Surat Tugas & TOR
            $isSuratTugas = $dokumenCetak->template_id == 3 || str_contains($namaDokumenFinal, 'surat tugas');
            $isTor        = str_contains($namaDokumenFinal, 'tor');

            if (!$isSuratTugas && !$isTor) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Gagal! Tombol selesai langsung hanya berlaku untuk dokumen Surat Tugas dan TOR.'
                ], 400);
            }

            // 🔑 Update tanggal_cetak di dokumen_cetak jika masih kosong
            if (empty($dokumenCetak->tanggal_cetak)) {
                $dokumenCetak->tanggal_cetak = now();
            }

            $pathFotoFinal = 'tanpa_ttd';
            $dokumenCetak->foto_ttd = $pathFotoFinal;
            $dokumenCetak->save(); // Simpan tanggal_cetak & foto_ttd ke DB

            // Pindahkan & simpan tanggal_cetak ke tabel induk 'dokumen'
            $this->simpanKeMasterDokumen($dokumenCetak, $pathFotoFinal, 0, $isSuratTugas);

            DB::commit();

            return response()->json([
                'status' => 'success', 
                'message' => 'Hore! Dokumen berhasil diselesaikan dan diarsipkan!',
                'tanggal_cetak' => $dokumenCetak->tanggal_cetak
            ], 200);
            
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 4. Toggle Status Aktif Dokumen
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
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    
    

    // =========================================================================
    // 🛠️ HELPER METHOD
    // =========================================================================

    private function simpanKeMasterDokumen(DokumenCetak $dokumenCetak, string $pathFotoFinal, int $opsiFoto, bool $isSuratTugas)
    {
        $userId = auth()->id() ?? $dokumenCetak->user_id;
        
        $karyawanId = DB::table('users')->where('id', $userId)->value('karyawan_id') 
                    ?? DB::table('karyawan')->value('id') 
                    ?? 1;

        $template = Template::find($dokumenCetak->template_id);
        $namaDokumenFinal = $dokumenCetak->nama_dokumen;
        $lowerNama = strtolower($namaDokumenFinal);

        if (str_contains($lowerNama, 'tor')) {
            $jenisEnum = 'tor';
        } elseif ($isSuratTugas) {
            $jenisEnum = 'surat_tugas';
        } else {
            $jenisEnum = str_contains($lowerNama, 'kerusakan') ? 'kerusakan' : 'penyerahan';
        }

        DB::table('dokumen')->insert([
            'template_id'      => $dokumenCetak->template_id,
            'user_id'          => $userId, 
            'jenis_dokumen_id' => $template?->jenis_dokumen_id ?? 1,
            'nomor_dokumen'    => $namaDokumenFinal,                          
            'tanggal_dokumen'  => $dokumenCetak->created_at ?? now(),    
            'penyerah_id'      => $karyawanId,                 
            'penerima_id'      => $karyawanId,                 
            'opsi_foto'        => $opsiFoto,                 
            'kolom_tambahan'   => is_string($dokumenCetak->variables) ? $dokumenCetak->variables : json_encode($dokumenCetak->variables),             
            'file_sblm_print'  => $dokumenCetak->file_path,
            'file_sesudah_ttd' => $pathFotoFinal,
            'jenis'            => $jenisEnum, 
            'tanggal_cetak'    => $dokumenCetak->tanggal_cetak ?? now(),                                           
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}