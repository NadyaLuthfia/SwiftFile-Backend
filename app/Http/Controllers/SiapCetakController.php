<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DokumenCetak;
use App\Models\Template;
use App\Services\DocumentGeneratorService;
use App\Services\DocumentNumberService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Carbon\Carbon;
use Throwable;

class SiapCetakController extends Controller
{
    protected $documentService;
    protected $numberService;

    public function __construct(DocumentGeneratorService $documentService, DocumentNumberService $numberService)
    {
        $this->documentService = $documentService;
        $this->numberService = $numberService;
    }

    /**
     * Menampilkan daftar dokumen antrean siap cetak milik user yang login
     */
    public function index(Request $request)
    {
        try {
            $query = DokumenCetak::where('user_id', $request->user()->id)->latest();

            if ($request->has('status')) {
                if ($request->status === 'belum_dicetak') {
                    $query->whereNull('file_path')->orWhere('file_path', '');
                } elseif ($request->status === 'sudah_dicetak') {
                    $query->whereNotNull('file_path')->where('file_path', '!=', '');
                }
            }

            $data = $query->get()->map(function ($dokumen) {
                $template = Template::find($dokumen->template_id);
                $namaTemplate = $template ? ($template->nama ?? $template->nama_template) : 'Dokumen';

                $vars = is_string($dokumen->variables) ? json_decode($dokumen->variables, true) : (array)$dokumen->variables;

                $lowerTemplate = strtolower($namaTemplate);
                $lowerDokumen = strtolower($dokumen->nama_dokumen ?? '');
                $isTor = str_contains($lowerTemplate, 'tor') || str_contains($lowerDokumen, 'tor');

                if ($isTor) {
                    $namaDetail = $vars['nama_pekerjaan'] 
                        ?? $vars['pekerjaan'] 
                        ?? $vars['nama_jasa'] 
                        ?? $vars['jasa'] 
                        ?? $vars['nama_barang'] 
                        ?? $vars['barang'] 
                        ?? $vars['nama_item'] 
                        ?? '';
                    $namaPenyerah = $vars['nama_penyerah'] ?? $vars['penyerah'] ?? $vars['nama_pembuat'] ?? $vars['pembuat'] ?? '';

                    $detailTor = trim("{$namaDetail} {$namaPenyerah}");
                    $namaOtomatis = !empty($detailTor) ? "{$namaTemplate} - {$detailTor}" : ($dokumen->nama_dokumen ?? $namaTemplate);
                } else {
                    $rawNo = $vars['no'] ?? 1;
                    $noUrut = is_numeric($rawNo) ? sprintf("%03d", (int)$rawNo) : $rawNo;

                    $tahun = $dokumen->created_at ? $dokumen->created_at->format('Y') : date('Y');
                    $bulanAngka = $dokumen->created_at ? (int)$dokumen->created_at->format('n') : (int)date('n');
                    $bulanRomawi = DocumentNumberService::getBulanRomawi($bulanAngka);

                    $kodeFormat = (str_contains($lowerTemplate, 'surat tugas') || str_contains($lowerDokumen, 'surat tugas')) ? "SPPD/O" : "BA";

                    $namaOtomatis = "{$namaTemplate} - {$noUrut}/BTG-{$bulanRomawi}/{$kodeFormat}/{$tahun}";
                }
                
                $dokumen->nama_dokumen = $namaOtomatis;
                $dokumen->nama_arsip_display = $namaOtomatis; 
                $dokumen->status = !empty($dokumen->file_path) ? 'sudah_dicetak' : 'belum_dicetak';

                return $dokumen;
            });
            
            return response()->json($data, 200);
            
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Menampilkan detail 1 dokumen siap cetak untuk auto-fill form edit
     */
    public function show(Request $request, $id)
    {
        try {
            $dokumen = DokumenCetak::with('template')->findOrFail($id);
            
            // Cek Akses
            if ($dokumen->user_id != $request->user()->id) {
                return response()->json(['message' => 'Anda tidak memiliki akses ke dokumen ini.'], 403);
            }

            $vars = is_string($dokumen->variables) 
                ? json_decode($dokumen->variables, true) 
                : (array)$dokumen->variables;

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

            // Hapus key scan agar tidak dikirim ke frontend
            unset($vars['scan']);
            unset($vars['SCAN']);

            $dokumen->variables = $vars;

            return response()->json([
                'status' => 'success',
                'data' => $dokumen
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Dokumen tidak ditemukan.'], 404);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * KHUSUS TOMBOL "Selesai & Kirim Ke Antrean"
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi data gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Unboxing variabel seperti di DraftController
            $variables = $request->except(['template_id', '_token', '_method', 'foto_lama']);

            if (isset($variables['variables'])) {
                $vars = is_string($variables['variables']) ? json_decode($variables['variables'], true) : (array)$variables['variables'];
                unset($variables['variables']);
                if (is_array($vars)) $variables = array_merge($vars, $variables);
            }

            if (isset($variables['isian'])) {
                $isian = is_string($variables['isian']) ? json_decode($variables['isian'], true) : (array)$variables['isian'];
                unset($variables['isian']);
                if (is_array($isian)) $variables = array_merge($isian, $variables);
            }

            // --- ✨ TAMBAHAN FIX: Tangkap dan Decode format `items` ---
            if (isset($variables['items'])) {
                if (is_string($variables['items'])) {
                    $variables['items'] = json_decode($variables['items'], true);
                }
                if (!is_array($variables['items'])) {$variables['items'] = [];
                }
            }
            // -----------------------------------------------------------

            // Handle Foto Lama
            $fotoLama = [];
            if ($request->has('foto_lama')) {
                $rawFotoLama =$request->input('foto_lama');
                $fotoLama = is_string($rawFotoLama) ? json_decode($rawFotoLama, true) : (array)$rawFotoLama;
            }

            // Handle Upload Foto Baru
            $uploadedPhotos = [];$photoField = $request->hasFile('foto') ? 'foto' : ($request->hasFile('foto_barang') ? 'foto_barang' : null);

            if ($photoField) {$dirPath = public_path('uploads/lampiran');
                if (!is_dir($dirPath)) {
                    @mkdir($dirPath, 0777, true);
                }

                $files = is_array($request->file($photoField)) ? $request->file($photoField) : [$request->file($photoField)];

                foreach ($files as$file) {
                    if ($file && $file->isValid()) {$filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();$file->move($dirPath,$filename);

                        $uploadedPhotos[] = [
                            'path'       => 'uploads/lampiran/' . $filename,
                            'keterangan' => 'Foto Lampiran'
                        ];
                    }
                }
            }

            $semuaFoto = array_merge((array)$fotoLama,$uploadedPhotos);
            if (!empty($semuaFoto)) {
                $variables['foto'] =$semuaFoto;
            }

            // Generate nomor dokumen otomatis
            $numData = $this->numberService->generate($request->template_id);
            $variables['no'] =$numData['no'];
            $variables['bulan'] =$numData['bulan_teks'];
            $variables['tahun'] =$numData['tahun'];

            // Penamaan dokumen
            $template = Template::find($request->template_id);
            $namaTemplate =$template ? ($template->nama ?? $template->nama_template) : 'Dokumen';
            $lowerTemplate = strtolower($namaTemplate);

            if (str_contains($lowerTemplate, 'tor')) {
                $namaDetail =$variables['nama_pekerjaan'] 
                    ?? $variables['pekerjaan'] 
                    ?? $variables['nama_jasa'] 
                    ?? $variables['jasa'] 
                    ?? $variables['nama_barang'] 
                    ?? $variables['barang'] 
                    ?? $variables['nama_item'] 
                    ?? '';
                $namaPenyerah = $variables['nama_penyerah'] ?? $variables['penyerah'] ?? $variables['nama_pembuat'] ?? $variables['pembuat'] ?? '';

                $detailTor = trim("{$namaDetail} {$namaPenyerah}");
                $namaDokumenFinal = !empty($detailTor) ? "{$namaTemplate} - {$detailTor}" : "{$namaTemplate} - " . uniqid();
            } else {
                $namaDokumenFinal = $numData['nama_dokumen_final'] ?? $namaTemplate;
            }

            $siapCetak = DokumenCetak::create([
                'user_id'      => $request->user()->id,
                'template_id'  => $request->template_id,
                'nama_dokumen' => $namaDokumenFinal,
                'variables'    => $variables, 
            ]);

            $filePathFisik = $this->documentService->generate($siapCetak);
            $siapCetak->file_path =$filePathFisik;
            $siapCetak->save();

            $draftId = $request->input('draft_id') ?? ($variables['draft_id'] ?? null);
            if ($draftId) {
                DB::table('drafts')->where('id', $draftId)->delete();
            }

            DB::commit();

            $siapCetak->status = 'sudah_dicetak';

            return response()->json([
                'status' => 'success',
                'message' => 'Dokumen selesai dan berhasil dikirim ke antrean cetak!',
                'data' => $siapCetak
            ], 201);

        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menyimpan dokumen ke antrean.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 📝 FITUR EDIT / REVISI DOKUMEN SIAP CETAK + HISTORY
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $user = $request->user();
            $dokumen = DokumenCetak::findOrFail($id);

            // 1. Pengecekan Hak Akses
            if ($dokumen->user_id != $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Kamu tidak memiliki akses untuk mengubah dokumen ini.'
                ], 403);
            }

            // 2. Ambil data lama buat dibandingin
            $oldVars = is_array($dokumen->variables) 
                ? $dokumen->variables 
                : (json_decode($dokumen->variables, true) ?? []);

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
                if (is_string($inputBaru['items'])) {
                    $inputBaru['items'] = json_decode($inputBaru['items'], true);
                }
                if (!is_array($inputBaru['items'])) {
                    $inputBaru['items'] = [];
                }
            }

            // 🕵️‍♀ 4. FITUR HISTORY: Deteksi Perubahan Data
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

            if (!empty($perubahanTercatat)) {
                $historyLama = is_string($dokumen->history_perubahan) 
                    ? json_decode($dokumen->history_perubahan, true) 
                    : (array)$dokumen->history_perubahan;

                $historyLama[] = [
                    'waktu' => now()->toDateTimeString(),
                    'diubah_oleh' => $user->name ?? $user->username ?? 'User',
                    'perubahan' => $perubahanTercatat
                ];

                $dokumen->history_perubahan = $historyLama;
            }

            // 5. Penggabungan Foto Lama & Baru
            $fotoFinal = [];
            if ($request->has('foto_lama')) {
                $fotoLama = is_string($request->foto_lama) ? json_decode($request->foto_lama, true) : (array)$request->foto_lama;
                if (is_array($fotoLama)) {
                    $fotoFinal = $fotoLama;
                }
            } elseif (isset($oldVars['foto'])) {
                $fotoFinal = (array)$oldVars['foto'];
            }

            $photoField = $request->hasFile('foto') ? 'foto' : ($request->hasFile('foto_barang') ? 'foto_barang' : null);

            if ($photoField) {
                $dirPath = public_path('uploads/lampiran');
                if (!is_dir($dirPath)) {
                    @mkdir($dirPath, 0777, true);
                }

                $files = is_array($request->file($photoField)) ? $request->file($photoField) : [$request->file($photoField)];

                foreach ($files as $file) {
                    if ($file && $file->isValid()) {
                        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                        $file->move($dirPath, $filename);

                        $fotoFinal[] = [
                            'path'       => 'uploads/lampiran/' . $filename,
                            'keterangan' => 'Foto Lampiran (Revisi)'
                        ];
                    }
                }
            }

            // 6. Gabungkan Variabel Lama dengan Input Baru
            $variablesMerged = array_merge($oldVars, $inputBaru);
            if (!empty($fotoFinal)) {
                $variablesMerged['foto'] = $fotoFinal;
            }

            // 7. Simpan Variabel Terbaru
            $dokumen->variables = $variablesMerged;
            
            // 📝 8. UPDATE FILE WORD (Overwriting)
            $filePathFisik = $this->documentService->generate($dokumen);
            $dokumen->file_path = $filePathFisik; 
            $dokumen->tanggal_cetak = null;

            // Simpan ke DB dulu
            $dokumen->save();

            DB::commit();

            // Baru kasih status virtual
            $dokumen->status = 'belum_dicetak';

            return response()->json([
                'status' => 'success',
                'message' => 'Dokumen siap cetak berhasil diperbarui dan word tertimpa!',
                'data' => $dokumen
            ], 200);

        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menyimpan revisi dokumen.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Memperbarui tanggal cetak dan melakukan kompilasi PDF
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $dokumen = DokumenCetak::findOrFail($id);
            
            // Selalu update tanggal cetak ke waktu sekarang setiap kali fungsi dipanggil
            $dokumen->tanggal_cetak = now();
            $dokumen->save();

            $pdfRelativePath = $this->documentService->generatePdf($dokumen);

            return response()->json([
                'status' => 'success', 
                'message' => 'PDF siap dicetak!',
                'pdf_url' => asset($pdfRelativePath) . '?t=' . time(),
                'tanggal_cetak' => $dokumen->tanggal_cetak
            ], 200);
            
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mengunduh/menampilkan langsung file PDF dokumen
     */
    public function cetak($id)
    {
        try {
            $dokumen = DokumenCetak::findOrFail($id);

            $pdfRelativePath = $this->documentService->generatePdf($dokumen);
            $filePathFisik = public_path($pdfRelativePath);

            return response()->file($filePathFisik, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . ($dokumen->nama_dokumen ?? 'dokumen') . '.pdf"'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dokumen tidak ditemukan.'
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}