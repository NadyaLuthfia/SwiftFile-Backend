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
     * 1. Menampilkan Daftar Dokumen Arsip (Hanya dari tabel master 'dokumen')
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user(); 
            
            // Query dari tabel master 'dokumen'
            $query = DB::table('dokumen')->latest();

            // Filter User
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

            // Mapping Response agar struktur key sesuai dengan kebutuhan Frontend
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
                    'file_word'          => $dokumen->file_word ?? null, // PERBAIKAN: mengambil kolom file_word yang benar
                    'tanggal_cetak'      => $dokumen->tanggal_cetak,
                    'status_aktif'       => $dokumen->status_aktif ?? 1,
                    'jenis'              => $dokumen->jenis,
                    'created_at'         => $dokumen->created_at,
                    'items'              => [],
                ];
            });
            
            return response()->json($data, 200);
            
        } catch (Throwable $e) {
            Log::error('ArsipController Index Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
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

                if (empty($dokumenCetak->tanggal_cetak)) {
                    $dokumenCetak->tanggal_cetak = now();
                }

                // Simpan ke tabel master 'dokumen' (dengan proteksi duplikasi)
                $this->simpanKeMasterDokumen($dokumenCetak, $pathFotoFinal, false);

                // Hapus permanen dari tabel 'dokumen_cetak' agar tidak memenuhi database
                $dokumenCetak->forceDelete();
            }

            DB::commit();

            return response()->json([
                'status' => 'success', 
                'message' => 'Hore! Bukti TTD berhasil diupload dan data masuk ke arsip!'
            ], 200);
            
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ArsipController uploadBukti Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 3. Selesai Direct (Berlaku untuk SEMUA Jenis Dokumen tanpa upload foto TTD)
     */
    public function selesaiSuratTugas($id)
    {
        DB::beginTransaction();

        try {
            $dokumenCetak = DokumenCetak::findOrFail($id);
            $namaDokumenFinal = strtolower($dokumenCetak->nama_dokumen ?? '');

            $isSuratTugas = $dokumenCetak->template_id == 3 || str_contains($namaDokumenFinal, 'surat tugas') || str_contains($namaDokumenFinal, 'tugas');

            if (empty($dokumenCetak->tanggal_cetak)) {
                $dokumenCetak->tanggal_cetak = now();
            }

            $pathFotoFinal = 'tanpa_ttd';

            // Simpan ke tabel master 'dokumen' (dengan proteksi duplikasi)
            $this->simpanKeMasterDokumen($dokumenCetak, $pathFotoFinal, $isSuratTugas);

            // Hapus permanen dari tabel 'dokumen_cetak' agar tidak memenuhi database
            $dokumenCetak->forceDelete();

            DB::commit();

            return response()->json([
                'status' => 'success', 
                'message' => 'Hore! Dokumen berhasil diselesaikan dan diarsipkan!'
            ], 200);
            
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ArsipController selesaiSuratTugas Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 4. Toggle Status Aktif Dokumen (Master Dokumen)
     */
    public function toggleAktif($id)
    {
        try {
            $dokumen = DB::table('dokumen')->where('id', $id)->first();
            if (!$dokumen) {
                return response()->json(['status' => 'error', 'message' => 'Dokumen tidak ditemukan'], 444);
            }

            $statusAktifBaru = (isset($dokumen->status_aktif) && $dokumen->status_aktif == 1) ? 0 : 1;
            DB::table('dokumen')->where('id', $id)->update(['status_aktif' => $statusAktifBaru]);

            return response()->json([
                'status' => 'success',
                'message' => $statusAktifBaru == 1 ? 'Dokumen berhasil diaktifkan!' : 'Dokumen berhasil dinonaktifkan!',
                'status_aktif' => $statusAktifBaru
            ], 200);
            
        } catch (Throwable $e) {
            Log::error('ArsipController toggleAktif Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 5. Cetak Lagi (Ambil PDF di folder, jika tidak ada generate dari Word)
     */
    public function cetakLagi($id)
    {
        try {
            // Ambil data arsip dari master 'dokumen'
            $dokumen = DB::table('dokumen')->where('id', $id)->first();

            if (!$dokumen) {
                return response()->json(['status' => 'error', 'message' => 'Dokumen tidak ditemukan di arsip.'], 404);
            }

            if (empty($dokumen->file_word)) {
                return response()->json(['status' => 'error', 'message' => 'Referensi file Word tidak ditemukan, tidak bisa mencetak dokumen.'], 404);
            }

            // Tentukan lokasi PDF (biasanya replace dari .docx ke .pdf dan folder documents ke pdfs)
            $pdfRelativePath = str_replace(['documents/', '.docx'], ['pdfs/', '.pdf'], $dokumen->file_word);
            $filePathFisik = public_path($pdfRelativePath);

            // Jika PDF-nya belum ada secara fisik, buat PDF-nya dari Word
            if (!file_exists($filePathFisik)) {
                
                // Pastikan file Word fisiknya beneran ada dulu sebelum nge-generate
                if (!file_exists(public_path($dokumen->file_word))) {
                    return response()->json(['status' => 'error', 'message' => 'File Word asli tidak ditemukan, gagal men-generate PDF.'], 404);
                }

                // Buat mock objek "DokumenCetak" agar DocumentGeneratorService tidak error karena tipe data
                $mockDokumen = new DokumenCetak();
                $mockDokumen->file_path = $dokumen->file_word;
                $mockDokumen->nama_dokumen = $dokumen->nomor_dokumen;

                // Panggil service untuk men-generate ulang PDF
                $pdfRelativePath = $this->documentService->generatePdf($mockDokumen);
                $filePathFisik = public_path($pdfRelativePath);
            }

            // TANDA CETAK: Update kolom tanggal_cetak dengan waktu sekarang
            DB::table('dokumen')->where('id', $id)->update([
                'tanggal_cetak' => now()
            ]);

            // Kembalikan respon JSON dengan URL PDF
            return response()->json([
                'status' => 'success',
                'message' => 'Dokumen berhasil dikonversi dan tanggal cetak diperbarui!',
                'pdf_url' => asset($pdfRelativePath)
            ], 200);

        } catch (Throwable $e) {
            Log::error('ArsipController cetakLagi Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Gagal mencetak ulang dokumen: ' . $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // 🛠️ HELPER METHOD
    // =========================================================================

    private function simpanKeMasterDokumen(DokumenCetak $dokumenCetak, string $pathFotoFinal, bool $isSuratTugas)
    {
        $userId = auth()->id() ?? $dokumenCetak->user_id;
        $namaDokumenFinal = $dokumenCetak->nama_dokumen ?? 'Tanpa Nama';

        // 🛡️ Cegah Data Double: Cek apakah dokumen ini sudah di-insert ke tabel 'dokumen'
        $sudahAda = DB::table('dokumen')
            ->where('user_id', $userId)
            ->where('nomor_dokumen', $namaDokumenFinal)
            ->exists();

        if ($sudahAda) {
            return; // Hentikan proses insert jika data sudah terdaftar
        }
        
        $karyawanId = DB::table('users')->where('id', $userId)->value('karyawan_id') 
                    ?? DB::table('karyawan')->value('id') 
                    ?? 1;

        $template = Template::find($dokumenCetak->template_id);
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
            'kolom_tambahan'   => is_string($dokumenCetak->variables) ? $dokumenCetak->variables : json_encode($dokumenCetak->variables),             
            'file_word'        => $dokumenCetak->file_path, // PERBAIKAN: menyimpan lokasi file public/documents... asli
            'jenis'            => $jenisEnum, 
            'tanggal_cetak'    => $dokumenCetak->tanggal_cetak ?? now(),                                           
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}