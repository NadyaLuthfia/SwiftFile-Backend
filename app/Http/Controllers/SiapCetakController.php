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
     * KHUSUS TOMBOL "Selesai & Kirim Ke Antrean"
     */
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

            $fotoLama = [];
            if ($request->has('foto_lama')) {
                $rawFotoLama = $request->input('foto_lama');
                $fotoLama = is_string($rawFotoLama) ? json_decode($rawFotoLama, true) : (array)$rawFotoLama;
            }

            $uploadedPhotos = [];
            if ($request->hasFile('foto')) {
                $dirPath = public_path('uploads/lampiran');
                if (!is_dir($dirPath)) {
                    @mkdir($dirPath, 0777, true);
                }

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
            }

            $semuaFoto = array_merge((array)$fotoLama, $uploadedPhotos);
            if (!empty($semuaFoto)) {
                $variables['foto'] = $semuaFoto;
            }

            $numData = $this->numberService->generate($request->template_id);

            $variables['no'] = $numData['no'];
            $variables['bulan'] = $numData['bulan_teks'];
            $variables['tahun'] = $numData['tahun'];

            // 🛠️ PERBAIKAN UTAMA: Buat penamaan spesifik & unik sebelum disimpan ke Database
            $template = Template::find($request->template_id);
            $namaTemplate = $template ? ($template->nama ?? $template->nama_template) : 'Dokumen';
            $lowerTemplate = strtolower($namaTemplate);

            if (str_contains($lowerTemplate, 'tor')) {
                $namaDetail = $variables['nama_pekerjaan'] 
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

            // Simpan ke Antrean Siap Cetak dengan nama unik
            $siapCetak = DokumenCetak::create([
                'user_id'      => $request->user()->id,
                'template_id'  => $request->template_id,
                'nama_dokumen' => $namaDokumenFinal,
                'variables'    => $variables, 
            ]);

            // Generate Word (.docx) & simpan path ke DB
            $filePathFisik = $this->documentService->generate($siapCetak);
            $siapCetak->file_path = $filePathFisik;
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
     * Memperbarui tanggal cetak dan melakukan kompilasi PDF
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $dokumen = DokumenCetak::findOrFail($id);
            
            if (empty($dokumen->tanggal_cetak)) {
                $dokumen->tanggal_cetak = now();
                $dokumen->save();
            }

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