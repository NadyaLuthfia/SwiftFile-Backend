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
     * 📄 Halaman Arsip: Hanya menampilkan dokumen yang dibuat oleh user yang sedang login
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user(); 
            
            $query = DB::table('dokumen')->latest();

            if ($user) {
                // Kunci filter: Hanya dokumen yang dibuat oleh user ini
                $query->where('user_id', $user->id);
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
                    'history_perubahan'  => $variables,
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
     * 1.B Menampilkan Daftar SEMUA Dokumen Arsip 
     * 📂 Halaman Arsip Dokumen: Untuk Admin & Master Admin melihat semua arsip
     */
    public function arsipDokumen(Request $request)
    {
        try {
            // Mengambil semua data dari tabel dokumen dan join dengan tabel users
            // untuk mendapatkan nama pembuat dokumen
            $dokumenList = DB::table('dokumen')
                ->leftJoin('users', 'dokumen.user_id', '=', 'users.id')
                ->select('dokumen.*', 'users.username as pembuat_name')
                ->latest('dokumen.created_at')
                ->get();

            $data = $dokumenList->map(function($dokumen) {
                $variables = is_string($dokumen->kolom_tambahan) 
                    ? json_decode($dokumen->kolom_tambahan, true) 
                    : $dokumen->kolom_tambahan;

                return [
                    'id'                 => $dokumen->id,
                    'template_id'        => $dokumen->template_id,
                    'user_id'            => $dokumen->user_id,
                    'pembuat'            => $dokumen->pembuat_name, // Dikirim ke React sebagai 'pembuat'
                    'nama_dokumen'       => $dokumen->nomor_dokumen,
                    'nomor_dokumen'      => $dokumen->nomor_dokumen,
                    'nama_arsip_display' => $dokumen->nomor_dokumen,
                    'variables'          => $variables,
                    'kolom_tambahan'     => $variables,
                    'history_perubahan'  => $variables,
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
            
            return response()->json([
                'success' => true,
                'data'    => $data
            ], 200);
            
        } catch (Throwable $e) {
            Log::error('ArsipController arsipDokumen Error: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal mengambil data arsip keseluruhan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menampilkan detail 1 dokumen arsip untuk auto-fill form edit
     */
    public function show(Request $request, $id)
    {
        try {
            $dokumen = DB::table('dokumen')->where('id', $id)->first();
            
            if (!$dokumen) {
                return response()->json(['message' => 'Dokumen arsip tidak ditemukan.'], 404);
            }

            // Cek Akses: Izinkan jika pemilik dokumen ATAU jika user adalah Admin / Master Admin
            $user = $request->user();
            $isAdmin = $user && (in_array($user->role ?? '', ['admin', 'master_admin']) || ($user->is_admin ?? false));
            
            if ($user && $dokumen->user_id != $user->id && !$isAdmin) {
                return response()->json(['message' => 'Anda tidak memiliki akses ke dokumen ini.'], 403);
            }

            // Di tabel dokumen, variabel disimpan di 'kolom_tambahan'
            $vars = is_string($dokumen->kolom_tambahan) 
                ? json_decode($dokumen->kolom_tambahan, true) 
                : (array)$dokumen->kolom_tambahan;

            // Unboxing variabel bertumpuk agar form dapat langsung membaca field secara flat
            if (isset($vars['variables'])) {
                $inner = is_string($vars['variables']) ? json_decode($vars['variables'], true) : (array)$vars['variables'];
                unset($vars['variables']);
                if (is_array($inner)) $vars = array_merge($inner, $vars);
            }

            if (isset($vars['isian'])) {
                $innerIsian = is_string($vars['isian']) ? json_decode($vars['isian'], true) : (array)$vars['isian'];
                unset($vars['isian']);
                if (is_array($innerIsian)) $vars = array_merge($innerIsian, $vars);
            }

            // Frontend butuh field 'variables' dan 'template_id'
            $dokumen->variables = $vars;

            return response()->json([
                'status' => 'success',
                'data' => $dokumen
            ], 200);

        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 📝 FITUR EDIT / REVISI DOKUMEN ARSIP + HISTORY
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $user = $request->user();
            $dokumen = DB::table('dokumen')->where('id', $id)->first();

            // 1. Pengecekan Hak Akses
            if (!$dokumen) {
                return response()->json(['status' => 'error', 'message' => 'Dokumen arsip tidak ditemukan.'], 404);
            }

            // Cek apakah user adalah pemilik dokumen ATAU Admin / Master Admin
            $isAdmin = $user && (in_array($user->role ?? '', ['admin', 'master_admin']) || ($user->is_admin ?? false));
            
            if ($dokumen->user_id != $user->id && !$isAdmin) {
                return response()->json(['status' => 'error', 'message' => 'Kamu tidak memiliki akses untuk mengubah dokumen ini.'], 403);
            }

            // 2. Ambil data lama buat dibandingin (dari kolom_tambahan)
            $oldVars = is_string($dokumen->kolom_tambahan) 
                ? json_decode($dokumen->kolom_tambahan, true) 
                : (array)$dokumen->kolom_tambahan;

            // 3. Unboxing Payload Input Baru
            $inputBaru = $request->except(['template_id', '_token', '_method', 'foto_lama']);

            if (isset($inputBaru['variables'])) {
                $vars = is_string($inputBaru['variables']) ? json_decode($inputBaru['variables'], true) : (array)$inputBaru['variables'];
                unset($inputBaru['variables']);
                if (is_array($vars)) $inputBaru = array_merge($vars, $inputBaru);
            }

            if (isset($inputBaru['isian'])) {
                $isian = is_string($inputBaru['isian']) ? json_decode($inputBaru['isian'], true) : (array)$inputBaru['isian'];
                unset($inputBaru['isian']);
                if (is_array($isian)) $inputBaru = array_merge($isian, $inputBaru);
            }

            if (isset($inputBaru['items'])) {
                if (is_string($inputBaru['items'])) $inputBaru['items'] = json_decode($inputBaru['items'], true);
                if (!is_array($inputBaru['items'])) $inputBaru['items'] = [];
            }

            // 🕵️‍♀️ 4. FITUR HISTORY: Deteksi Perubahan Data
            $perubahanTercatat = [];
            foreach ($inputBaru as $key => $newValue) {
                $oldValue = $oldVars[$key] ?? null;
                if ($oldValue != $newValue && !in_array($key, ['foto', 'foto_lama', 'foto_barang'])) {
                    $perubahanTercatat[] = [
                        'field' => $key,
                        'dari'  => $oldValue,
                        'menjadi' => $newValue
                    ];
                }
            }

            $historyLamaArray = is_string($dokumen->history_perubahan) 
                ? json_decode($dokumen->history_perubahan, true) 
                : (array)$dokumen->history_perubahan;

            if (!empty($perubahanTercatat)) {
                $historyLamaArray[] = [
                    'waktu' => now()->toDateTimeString(),
                    'diubah_oleh' => $user->name ?? $user->username ?? 'User',
                    'perubahan' => $perubahanTercatat
                ];
            }

            // 5. Penggabungan Foto Lama & Baru
            $fotoFinal = [];
            if ($request->has('foto_lama')) {
                $fotoLama = is_string($request->foto_lama) ? json_decode($request->foto_lama, true) : (array)$request->foto_lama;
                if (is_array($fotoLama)) $fotoFinal = $fotoLama;
            } elseif (isset($oldVars['foto'])) {
                $fotoFinal = (array)$oldVars['foto'];
            }

            $photoField = $request->hasFile('foto') ? 'foto' : ($request->hasFile('foto_barang') ? 'foto_barang' : null);
            if ($photoField) {
                $dirPath = public_path('uploads/lampiran');
                if (!is_dir($dirPath)) @mkdir($dirPath, 0777, true);

                $files = is_array($request->file($photoField)) ? $request->file($photoField) : [$request->file($photoField)];
                foreach ($files as $file) {
                    if ($file && $file->isValid()) {
                        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                        $file->move($dirPath, $filename);
                        $fotoFinal[] = [
                            'path'       => 'uploads/lampiran/' . $filename,
                            'keterangan' => 'Foto Lampiran (Revisi Arsip)'
                        ];
                    }
                }
            }

            // 6. Gabungkan Variabel Lama dengan Input Baru
            $variablesMerged = array_merge($oldVars, $inputBaru);
            if (!empty($fotoFinal)) {
                $variablesMerged['foto'] = $fotoFinal;
            }
            
            // 📝 7. UPDATE FILE WORD & PDF (Overwriting)
            $mockDokumen = DokumenCetak::find($id) ?? new DokumenCetak();
            $mockDokumen->exists = true; 
            $mockDokumen->id = $dokumen->id;
            $mockDokumen->template_id = $dokumen->template_id;
            $mockDokumen->nama_dokumen = $dokumen->nomor_dokumen;
            $mockDokumen->variables = $variablesMerged;

            // Generate ulang file Word
            $filePathFisik = $this->documentService->generate($mockDokumen);

            // Generate ulang file PDF
            $mockDokumen->file_path = $filePathFisik; 
            $pdfPathFisik = $this->documentService->generatePdf($mockDokumen);

            // 8. Update data di tabel dokumen arsip
            DB::table('dokumen')->where('id', $id)->update([
                'kolom_tambahan' => json_encode($variablesMerged),
                'history_perubahan' => json_encode($historyLamaArray),
                'file_word' => $filePathFisik,
                'tanggal_cetak' => now(), 
                'updated_at' => now()
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Dokumen arsip berhasil diperbarui dan word tertimpa!',
            ], 200);

        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ArsipController update Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menyimpan revisi arsip.',
                'error' => $e->getMessage()
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

    public function preview($id)
    {
        try {
            // Menggunakan DB table agar konsisten dengan fungsi lain di controller ini
            $dokumen = DB::table('dokumen')->where('id', $id)->first();

            if (!$dokumen) {
                return response()->json([
                    'message' => 'Dokumen tidak ditemukan di Arsip.'
                ], 404);
            }

            // Kalau file_word-nya nggak ada, ya nggak bisa di-preview
            if (empty($dokumen->file_word)) {
                 return response()->json([
                    'message' => 'File Word belum tersedia, sehingga PDF tidak dapat ditemukan.'
                ], 404);
            }

            // Triknya di sini sayang! Kita ganti teksnya secara otomatis
            // Ubah "documents/" jadi "pdfs/" dan ".docx" jadi ".pdf"
            $namaFilePdf = str_replace(['documents/', '.docx'], ['pdfs/', '.pdf'], $dokumen->file_word);
            
            // Arahkan ke lokasi fisik filenya di folder public
            $pathToFile = public_path($namaFilePdf);

            // Cek apakah file fisiknya beneran ada di folder public/pdfs
            if (!file_exists($pathToFile)) {
                return response()->json([
                    'message' => 'Dokumen PDF belum tersedia atau tidak ditemukan di Arsip.'
                ], 404);
            }

            // 🌟 TAMBAHKAN KODE INI UNTUK UPDATE TANGGAL CETAK 🌟
            DB::table('dokumen')->where('id', $id)->update([
                'tanggal_cetak' => now()
            ]);
            // 🚀 ============================================== 🚀

            // Kalau ada, kembalikan URL-nya supaya bisa dibuka/didownload
            return response()->json([
                // Pake asset() biar ngebentuk URL lengkap 
                'pdf_url' => asset($namaFilePdf) 
            ]);
            
        } catch (Throwable $e) {
            Log::error('ArsipController preview Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Gagal memuat preview: ' . $e->getMessage()], 500);
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
            'history_perubahan' => $dokumenCetak->history_perubahan,        
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