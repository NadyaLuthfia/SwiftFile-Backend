<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DokumenCetak;
use App\Models\Template;
use App\Services\DocumentGeneratorService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            
            $query = DB::table('dokumen')->latest();

            if ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id);
                    
                    $identity = $user->name ?? $user->username ?? $user->nama ?? null;
                    if (!empty($identity)) {
                        $q->orWhereRaw("LOWER(CAST(kolom_tambahan AS CHAR)) LIKE ?", ['%' . strtolower($identity) . '%']);
                    }
                });
            }

            $dokumenList = $query->get();

            $data = $dokumenList->map(function($dokumen) {
                $variables = is_string($dokumen->kolom_tambahan) 
                    ? json_decode($dokumen->kolom_tambahan, true) 
                    : $dokumen->kolom_tambahan;

                return [
                    'id'                 => $dokumen->id,
                    'template_id'        => $dokumen->template_id,
                    'user_id'            => $dokumen->user_id,
                    'nama_dokumen'       => $dokumen->nomor_dokumen,
                    'nomor_dokumen'      => $dokumen->nomor_dokumen,
                    'nama_arsip_display' => $dokumen->nomor_dokumen,
                    'variables'          => $variables,
                    'kolom_tambahan'     => $variables,
                    'input_data'         => $variables,
                    'file_word'          => $dokumen->file_word ?? null,
                    'tanggal_cetak'      => $dokumen->tanggal_cetak,
                    'status_aktif'       => $dokumen->status_aktif ?? 1,
                    'jenis'              => $dokumen->jenis,
                    'jenis_dokumen'      => $dokumen->jenis,
                    'kategori'           => $dokumen->jenis,
                    'created_at'         => $dokumen->created_at,
                    'items'              => [],
                ];
            });
            
            return response()->json($data, 200);
            
        } catch (Throwable $e) {
            Log::error('ArsipController Index Error: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal mengambil data arsip: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 2. Upload Bukti TTD
     */
    public function uploadBukti(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'foto_ttd' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $dokumenCetak = DokumenCetak::find($id);
            if (!$dokumenCetak) {
                return response()->json(['status' => 'error', 'message' => 'Dokumen antrean cetak tidak ditemukan.'], 404);
            }

            if ($request->hasFile('foto_ttd')) {
                $file = $request->file('foto_ttd');
                $nameFile = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $subFolder = 'uploads/bukti_ttd';
                $targetDir = public_path($subFolder);

                if (!File::exists($targetDir)) {
                    File::makeDirectory($targetDir, 0775, true, true);
                }

                $file->move($targetDir, $nameFile);
                $pathFotoFinal = $subFolder . '/' . $nameFile;

                if (empty($dokumenCetak->tanggal_cetak)) {
                    $dokumenCetak->tanggal_cetak = now();
                }

                $this->simpanKeMasterDokumen($dokumenCetak, $pathFotoFinal, false);
                $dokumenCetak->forceDelete();
            }

            DB::commit();

            return response()->json([
                'status'  => 'success', 
                'message' => 'Hore! Bukti TTD berhasil diupload dan data masuk ke arsip!'
            ], 200);
            
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ArsipController uploadBukti Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 3. Selesai Direct (Semua jenis dokumen)
     */
    public function selesaiSuratTugas($id)
    {
        DB::beginTransaction();

        try {
            $dokumenCetak = DokumenCetak::find($id);
            if (!$dokumenCetak) {
                return response()->json(['status' => 'error', 'message' => 'Dokumen tidak ditemukan di antrean.'], 404);
            }

            $namaDokumenFinal = strtolower($dokumenCetak->nama_dokumen ?? '');
            $isSuratTugas = $dokumenCetak->template_id == 3 || str_contains($namaDokumenFinal, 'surat tugas') || str_contains($namaDokumenFinal, 'tugas');

            if (empty($dokumenCetak->tanggal_cetak)) {
                $dokumenCetak->tanggal_cetak = now();
            }

            $pathFotoFinal = 'tanpa_ttd';

            $this->simpanKeMasterDokumen($dokumenCetak, $pathFotoFinal, $isSuratTugas);
            $dokumenCetak->forceDelete();

            DB::commit();

            return response()->json([
                'status'  => 'success', 
                'message' => 'Hore! Dokumen berhasil diselesaikan dan diarsipkan!'
            ], 200);
            
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ArsipController selesaiSuratTugas Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 4. Toggle Status Aktif Dokumen
     */
    public function toggleAktif($id)
    {
        try {
            $dokumen = DB::table('dokumen')->where('id', $id)->first();
            if (!$dokumen) {
                return response()->json(['status' => 'error', 'message' => 'Dokumen tidak ditemukan'], 404);
            }

            $statusAktifBaru = (isset($dokumen->status_aktif) && $dokumen->status_aktif == 1) ? 0 : 1;
            DB::table('dokumen')->where('id', $id)->update(['status_aktif' => $statusAktifBaru]);

            return response()->json([
                'status'       => 'success',
                'message'      => $statusAktifBaru == 1 ? 'Dokumen berhasil diaktifkan!' : 'Dokumen berhasil dinonaktifkan!',
                'status_aktif' => $statusAktifBaru
            ], 200);
            
        } catch (Throwable $e) {
            Log::error('ArsipController toggleAktif Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 5. Cetak Lagi / Generate PDF Ulang dari Word Arsip
     */
    public function cetakLagi($id)
    {
        try {
            $dokumen = DB::table('dokumen')->where('id', $id)->first();

            if (!$dokumen) {
                return response()->json(['status' => 'error', 'message' => 'Dokumen tidak ditemukan di arsip.'], 404);
            }

            if (empty($dokumen->file_word)) {
                return response()->json(['status' => 'error', 'message' => 'Path file Word tidak terdaftar.'], 404);
            }

            // Normalisasi path file agar fleksibel di Windows dan Linux
            $cleanRelativePath = ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $dokumen->file_word), DIRECTORY_SEPARATOR);
            $fullPath = public_path($cleanRelativePath);

            if (!file_exists($fullPath)) {
                $fullPath = storage_path('app' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $cleanRelativePath);
            }

            if (!file_exists($fullPath)) {
                return response()->json(['status' => 'error', 'message' => 'File Word asli tidak ditemukan di server.'], 404);
            }

            $variables = is_string($dokumen->kolom_tambahan) 
                ? json_decode($dokumen->kolom_tambahan, true) 
                : $dokumen->kolom_tambahan;

            $mockDokumen = new DokumenCetak();
            $mockDokumen->id = $dokumen->id;
            $mockDokumen->file_path = $dokumen->file_word;
            $mockDokumen->nama_dokumen = $dokumen->nomor_dokumen;
            $mockDokumen->variables = $variables ?? [];

            // Panggil Service Konversi PDF
            $pdfRelativePath = $this->documentService->generatePdf($mockDokumen);

            DB::table('dokumen')->where('id', $id)->update([
                'tanggal_cetak' => now()
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Dokumen berhasil dikonversi!',
                'pdf_url' => asset($pdfRelativePath) . '?t=' . time()
            ], 200);

        } catch (Throwable $e) {
            Log::error('ArsipController cetakLagi Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'status'  => 'error', 
                'message' => 'Gagal konversi PDF via LibreOffice: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 6. Hapus Dokumen Arsip
     */
    public function destroy($id)
    {
        try {
            $dokumen = DB::table('dokumen')->where('id', $id)->first();
            if (!$dokumen) {
                return response()->json(['status' => 'error', 'message' => 'Dokumen tidak ditemukan.'], 404);
            }

            // Hapus file word jika ada
            if (!empty($dokumen->file_word)) {
                $filePath = public_path(ltrim($dokumen->file_word, '/\\'));
                if (File::exists($filePath)) {
                    File::delete($filePath);
                }
            }

            DB::table('dokumen')->where('id', $id)->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Dokumen arsip berhasil dihapus.'
            ], 200);

        } catch (Throwable $e) {
            Log::error('ArsipController destroy Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // HELPER METHOD (DILENGKAPI SAFETY FALLBACK ANTI-FAIL)
    // =========================================================================

    public function simpanKeMasterDokumen(DokumenCetak $dokumenCetak, string $pathFotoFinal, bool $isSuratTugas)
    {
        $userId = $dokumenCetak->user_id ?? auth()->id() ?? 1;
        $namaDokumenFinal = $dokumenCetak->nama_dokumen ?? 'Tanpa Nama';

        // 1. Cek Duplikasi berdasarkan lokasi File Word
        if (!empty($dokumenCetak->file_path)) {
            $sudahAda = DB::table('dokumen')
                ->where('user_id', $userId)
                ->where('file_word', $dokumenCetak->file_path)
                ->exists();

            if ($sudahAda) {
                return; 
            }
        }
        
        // 2. Ambil Karyawan ID secara Aman (Cegah Relasi Foreign Key Bikin Crash)
        $karyawanId = DB::table('users')->where('id', $userId)->value('karyawan_id');
        if (!$karyawanId || !DB::table('karyawan')->where('id', $karyawanId)->exists()) {
            $karyawanId = DB::table('karyawan')->value('id');
        }

        $template = Template::find($dokumenCetak->template_id);
        $lowerNama = strtolower($namaDokumenFinal);

        // 3. Tentukan Nilai Jenis Dokumen
        if (str_contains($lowerNama, 'tor')) {
            $jenisEnum = str_contains($lowerNama, 'barang') ? 'tor_barang' : 'tor_jasa';
        } elseif ($isSuratTugas || str_contains($lowerNama, 'surat tugas') || str_contains($lowerNama, 'tugas')) {
            $jenisEnum = 'surat_tugas';
        } else {
            $jenisEnum = str_contains($lowerNama, 'kerusakan') ? 'kerusakan' : 'penyerahan';
        }

        $insertData = [
            'template_id'      => $dokumenCetak->template_id,
            'user_id'          => $userId, 
            'jenis_dokumen_id' => $template?->jenis_dokumen_id ?? 1,
            'nomor_dokumen'    => $namaDokumenFinal,                          
            'tanggal_dokumen'  => $dokumenCetak->created_at ?? now(),    
            'kolom_tambahan'   => is_string($dokumenCetak->variables) ? $dokumenCetak->variables : json_encode($dokumenCetak->variables),             
            'file_word'        => $dokumenCetak->file_path, 
            'jenis'            => $jenisEnum, 
            'status_aktif'     => 1,
            'tanggal_cetak'    => $dokumenCetak->tanggal_cetak ?? now(),                                           
            'created_at'       => now(),
            'updated_at'       => now(),
        ];

        if (!empty($karyawanId)) {
            $insertData['penyerah_id'] = $karyawanId;
            $insertData['penerima_id'] = $karyawanId;
        }

        // 4. TRY-CATCH INSERT (Jika DB Menolak Enum 'tor_jasa' atau 'tor_barang', Otomatis Fallback ke 'tor')
        try {
            DB::table('dokumen')->insert($insertData);
        } catch (Throwable $e) {
            if (str_contains($jenisEnum, 'tor_')) {
                $insertData['jenis'] = 'tor';
                DB::table('dokumen')->insert($insertData);
            } else {
                throw $e;
            }
        }
    }
}