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

            $data = $query->get()->map(function($dokumen) {
                $template = Template::find($dokumen->template_id);
                $namaTemplate = $template ? ($template->nama ?? $template->nama_template) : 'Dokumen';

                $vars = is_string($dokumen->variables) ? json_decode($dokumen->variables, true) : (array)$dokumen->variables;

                $lowerTemplate = strtolower($namaTemplate);
                $lowerDokumen = strtolower($dokumen->nama_dokumen ?? '');
                $isTor = str_contains($lowerTemplate, 'tor') || str_contains($lowerDokumen, 'tor');

                if ($isTor) {
                    // Penamaan khusus TOR (diambil murni dari JSON variables)
                    $namaBarang = $vars['nama_barang'] ?? $vars['barang'] ?? $vars['nama_item'] ?? '';
                    $namaPenyerah = $vars['nama_penyerah'] ?? $vars['penyerah'] ?? $vars['nama_pembuat'] ?? $vars['pembuat'] ?? '';

                    $detailTor = trim("{$namaBarang} {$namaPenyerah}");
                    $namaOtomatis = !empty($detailTor) ? "{$namaTemplate} - {$detailTor}" : $namaTemplate;
                } else {
                    // Penamaan standar untuk BA & Surat Tugas (ST)
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

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_id' => 'required',
            'variables'   => 'required', 
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
            $variables = is_string($request->variables) ? json_decode($request->variables, true) : (array)$request->variables;

            // =========================================================================
            // TAMBAHAN: OLAH FOTO UPLOAD & MASUKKAN KE ARRAY $variables['foto']
            // =========================================================================
            if ($request->hasFile('foto')) {
                $dirPath = public_path('uploads/lampiran');
                if (!is_dir($dirPath)) {
                    @mkdir($dirPath, 0777, true);
                }

                $uploadedPhotos = [];
                $files = is_array($request->file('foto')) ? $request->file('foto') : [$request->file('foto')];

                foreach ($files as $file) {
                    if ($file->isValid()) {
                        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                        $file->move($dirPath, $filename);

                        $uploadedPhotos[] = [
                            'path'       => 'uploads/lampiran/' . $filename,
                            'keterangan' => 'Foto Lampiran'
                        ];
                    }
                }

                if (!empty($uploadedPhotos)) {
                    $variables['foto'] = $uploadedPhotos;
                }
            }

            $numData = $this->numberService->generate($request->template_id);

            $variables['no'] = $numData['no'];
            $variables['bulan'] = $numData['bulan_teks'];
            $variables['tahun'] = $numData['tahun'];

            $siapCetak = DokumenCetak::create([
                'user_id'      => $request->user()->id,
                'template_id'  => $request->template_id,
                'nama_dokumen' => $numData['nama_dokumen_final'],
                'variables'    => $variables, 
            ]);

            // Generate Word (.docx) & simpan path ke DB
            $filePathFisik = $this->documentService->generate($siapCetak);
            $siapCetak->file_path = $filePathFisik;
            $siapCetak->save();

            // Generate PDF awal sebagai snapshot
            $this->documentService->generatePdf($siapCetak);

            DB::commit();

            $siapCetak->status = 'sudah_dicetak';

            return response()->json([
                'status' => 'success',
                'message' => 'Data draft, file Word & PDF berhasil dibuat!',
                'data' => $siapCetak
            ], 201);

        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Gagal menyimpan draft.', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $newVariables = is_string($request->variables) ? json_decode($request->variables, true) : (array)$request->variables;
            $status = $request->input('status', $newVariables['status'] ?? 'draft');

            // =========================================================================
            // TAMBAHAN: OLAH FOTO JIKA ADA FOTO BARU SAAT UPDATE
            // =========================================================================
            if ($request->hasFile('foto')) {
                $dirPath = public_path('uploads/lampiran');
                if (!is_dir($dirPath)) {
                    @mkdir($dirPath, 0777, true);
                }

                $uploadedPhotos = [];
                $files = is_array($request->file('foto')) ? $request->file('foto') : [$request->file('foto')];

                foreach ($files as $file) {
                    if ($file->isValid()) {
                        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                        $file->move($dirPath, $filename);

                        $uploadedPhotos[] = [
                            'path'       => 'uploads/lampiran/' . $filename,
                            'keterangan' => 'Foto Lampiran'
                        ];
                    }
                }

                if (!empty($uploadedPhotos)) {
                    $newVariables['foto'] = $uploadedPhotos;
                }
            }

            $draft = DB::table('drafts')->where('id', $id)->first();

            if ($draft) {
                if ($status === 'siap_cetak') {
                    $templateId = $request->template_id ?? $draft->template_id;

                    $numData = $this->numberService->generate($templateId);
                    $newVariables['no'] = $numData['no'];
                    $newVariables['bulan'] = $numData['bulan_teks'];
                    $newVariables['tahun'] = $numData['tahun'];

                    $siapCetak = DokumenCetak::create([
                        'user_id'      => $request->user()->id,
                        'template_id'  => $templateId,
                        'nama_dokumen' => $numData['nama_dokumen_final'],
                        'variables'    => $newVariables,
                    ]);

                    // Generate file Word & PDF pertama kali saat status berubah jadi siap_cetak
                    $filePathFisik = $this->documentService->generate($siapCetak);
                    $siapCetak->file_path = $filePathFisik;
                    $siapCetak->save();

                    $this->documentService->generatePdf($siapCetak);

                    DB::table('drafts')->where('id', $id)->delete();

                    DB::commit();

                    return response()->json([
                        'status' => 'success',
                        'message' => 'Dokumen selesai dan berhasil dipindah ke Siap Cetak!',
                        'data' => $siapCetak
                    ], 200);
                } else {
                    DB::table('drafts')->where('id', $id)->update([
                        'data_input' => json_encode($newVariables),
                        'updated_at' => now(),
                    ]);

                    DB::commit();

                    return response()->json([
                        'status' => 'success',
                        'message' => 'Draft berhasil diperbarui!',
                        'data' => $newVariables
                    ], 200);
                }
            }

            $dokumen = DokumenCetak::findOrFail($id);
            $oldVariables = is_string($dokumen->variables) ? json_decode($dokumen->variables, true) : (array)$dokumen->variables;

            if (isset($oldVariables['no'])) { 
                $newVariables['no'] = $oldVariables['no']; 
            }

            // Jika tidak ada foto baru dikirim, pertahankan foto lama jika ada
            if (!isset($newVariables['foto']) && isset($oldVariables['foto'])) {
                $newVariables['foto'] = $oldVariables['foto'];
            }

            $dokumen->update([
                'variables' => $newVariables,
            ]);

            // Re-generate saat benar-benar melakukan UPDATE data secara sengaja
            $filePathFisik = $this->documentService->generate($dokumen);
            $dokumen->file_path = $filePathFisik;
            $dokumen->save();

            $this->documentService->generatePdf($dokumen);

            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'Draft & file dokumen berhasil diperbarui!', 'data' => $dokumen], 200);

        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Gagal diperbarui data draft: ' . $e->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $dokumen = DokumenCetak::findOrFail($id);
            $now = now();
            
            if (empty($dokumen->foto_ttd)) {
                DB::table('dokumen_cetak')
                    ->where('id', $id)
                    ->where(function($q) {
                        $q->whereNull('foto_ttd')->orWhere('foto_ttd', '');
                    })
                    ->update([
                        'tanggal_cetak' => $now,
                        'updated_at'    => $now
                    ]);

                $dokumen = DokumenCetak::findOrFail($id);
            }

            // Tentukan perkiraan lokasi relative file PDF dari file_path Word
            $pdfRelativePath = str_replace(['documents/', '.docx'], ['pdfs/', '.pdf'], $dokumen->file_path);

            // ✅ CEK FISIK: Jika PDF belum ada di folder, baru dipanggil generatePdf sekali
            if (empty($pdfRelativePath) || !file_exists(public_path($pdfRelativePath))) {
                $pdfRelativePath = $this->documentService->generatePdf($dokumen);
            }

            return response()->json([
                'status' => 'success', 
                'message' => 'PDF siap dicetak!',
                'pdf_url' => asset($pdfRelativePath),
                'tanggal_cetak' => $dokumen->tanggal_cetak
            ], 200);
            
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function cetak($id)
    {
        try {
            $dokumen = DokumenCetak::findOrFail($id);

            $pdfRelativePath = str_replace(['documents/', '.docx'], ['pdfs/', '.pdf'], $dokumen->file_path);
            $filePathFisik = public_path($pdfRelativePath);

            // Jika file PDF fisik tidak ada, baru fallback ke generatePdf
            if (!file_exists($filePathFisik)) {
                $pdfRelativePath = $this->documentService->generatePdf($dokumen);
                $filePathFisik = public_path($pdfRelativePath);
            }

            // Langsung kembalikan file PDF statis yang sudah tersimpan
            return response()->file($filePathFisik, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . ($dokumen->nama_dokumen ?? 'dokumen') . '.pdf"'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Dokumen tidak ditemukan.'], 404);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}