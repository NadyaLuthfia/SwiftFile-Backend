<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DokumenCetak;
use App\Models\Template;
use App\Services\DocumentGeneratorService; 
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class SiapCetakController extends Controller
{
    protected $documentService;

    public function __construct(DocumentGeneratorService $documentService)
    {
        $this->documentService = $documentService;
    }

    /**
     * 1. Menampilkan data dokumen + SINKRONISASI STATUS VIRTUAL
     */
    public function index(Request $request)
    {
        try {
            // 🔐 Hanya menampilkan dokumen milik user yang sedang login
            $query = DokumenCetak::where('user_id', $request->user()->id)->latest();

            // ✨ Filter status tetap bekerja lewat kondisi file_path secara realtime!
            if ($request->has('status')) {
                if ($request->status === 'belum_dicetak') {
                    $query->whereNull('file_path')->orWhere('file_path', '');
                } elseif ($request->status === 'sudah_dicetak') {
                    $query->whereNotNull('file_path')->where('file_path', '!=', '');
                }
            }

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

                // ✨ MEMBUAT STATUS VIRTUAL BIAR FRONTEND TIDAK ERROR ✨
                $dokumen->status = !empty($dokumen->file_path) ? 'sudah_dicetak' : 'belum_dicetak';

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
     * 2. MENYIMPAN DRAFT BARU + Format Otomatis BA Kerusakan
     * (Menghasilkan .docx agar bisa langsung didownload dan diedit)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_id'  => 'required',
            'nama_dokumen' => 'required|string|max:255',
            'variables'    => 'required', 
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
            $variablesInput = $request->variables;
            if (is_string($variablesInput)) {
                $variables = json_decode($variablesInput, true);
            } else {
                $variables = (array)$variablesInput;
            }

            $currentTemplate = Template::find($request->template_id);
            $templateNama = $currentTemplate ? strtolower($currentTemplate->nama) : '';
            
            // Deteksi jenis dokumen (Surat Tugas, Penyerahan, atau Kerusakan)
            $isSuratTugas = str_contains($templateNama, 'surat tugas') || str_contains(strtolower($request->nama_dokumen), 'surat tugas');
            $isBaPenyerahan = str_contains($templateNama, 'penyerahan') || str_contains(strtolower($request->nama_dokumen), 'penyerahan');
            $isBaKerusakan = str_contains($templateNama, 'kerusakan') || str_contains(strtolower($request->nama_dokumen), 'kerusakan');

            $groupedTemplateIds = Template::where('nama', $isSuratTugas ? 'like' : 'not like', '%surat tugas%')->pluck('id');
            
            // 🌎 Tetap global se-database seperti kodemu sebelumnya
            $lastDocument = DokumenCetak::whereIn('template_id', $groupedTemplateIds)->whereYear('created_at', now()->year)->latest('id')->first();

            $nextNo = '001'; 
            if ($lastDocument) {
                $lastVars = $lastDocument->variables;
                if (is_string($lastVars)) { $lastVars = json_decode($lastVars, true); } else { $lastVars = (array)$lastVars; }
                if (isset($lastVars['no']) && is_numeric($lastVars['no'])) {
                    $nextNo = str_pad((int)$lastVars['no'] + 1, 3, '0', STR_PAD_LEFT);
                }
            }
            $variables['no'] = $nextNo;

            $siapCetak = DokumenCetak::create([
                'user_id'      => $request->user()->id, // 🔐 Hanya ini tambahannya!
                'template_id'  => $request->template_id,
                'nama_dokumen' => $request->nama_dokumen,
                'variables'    => $variables, 
            ]);

            // Ambil nama item awal dari request / variables
            $namaItem = $request->nama_item ?? $variables['item'] ?? $variables['nama_item'] ?? $variables['nama_alat'] ?? '';
            
            if (!empty($namaItem) || $isBaKerusakan) {
                if ($isBaKerusakan) {
                    // ✨ Kondisi khusus BA Kerusakan (Sesuai Request-mu) ✨
                    $namaAlat = $variables['nama_alat'] ?? $namaItem ?? 'Alat';
                    $namaItem = $namaAlat; 
                    
                    $merk = $variables['merk'] ?? '';
                    $model = $variables['model'] ?? '';
                    $noSeri = $variables['no_seri'] ?? '';
                    $spesifikasiFinal = "Merk :{$merk}, Model :{$model}, No Seri :{$noSeri}";
                    
                    $jumlahFinal = 1;
                    
                    $namaLaporan = $variables['nama_laporan'] ?? $variables['nama_pelapor'] ?? '-';
                    $namaPenanggung = $variables['nama_penanggung'] ?? $variables['nama_penanggung_jawab'] ?? '-';
                    $keteranganFinal = "{$namaAlat} mengalami kerusakan yang dilapor oleh {$namaLaporan} dan ditanggung jawabkan oleh {$namaPenanggung}";
                } elseif ($isBaPenyerahan) {
                    // Kondisi khusus BA Penyerahan
                    $spesifikasiFinal = $request->spesifikasi ?? $variables['spesifikasi'] ?? '';
                    $jumlahFinal = 1;
                    $namaPenyerah = $variables['nama_penyerah'] ?? '-';
                    $namaPenerima = $variables['nama_penerima'] ?? '-';
                    $keteranganFinal = "{$namaItem} diserahkan oleh {$namaPenyerah} kepada {$namaPenerima}";
                } else {
                    // Kondisi default dokumen lain
                    $spesifikasiFinal = $request->spesifikasi ?: "Merk: " . ($variables['merk'] ?? '-') . ", Model: " . ($variables['model'] ?? '-') . ", No Seri: " . ($variables['no_seri'] ?? '-');
                    $jumlahFinal = isset($variables['nama_alat']) ? 1 : ($request->jumlah ?? 1); 
                    $keteranganFinal = $request->keterangan_item ?? $variables['keterangan_kerusakan'] ?? '';
                }

                DB::table('dokumen_item')->insert([
                    'dokumen_id'  => $siapCetak->id,
                    'nama_item'   => $namaItem,
                    'spesifikasi' => $spesifikasiFinal,
                    'jumlah'      => $jumlahFinal, 
                    'keterangan'  => $keteranganFinal,
                    'created_at'  => now(), 
                    'updated_at'  => now(),
                ]);
            }

            if ($request->allFiles()) {
                foreach ($request->allFiles() as $fileKey => $file) {
                    if ($fileKey === 'foto_ttd') continue; 
                    $nameFile = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $file->move(public_path('uploads/dokumen'), $nameFile);
                    $pathFotoFinal = 'uploads/dokumen/' . $nameFile;

                    DB::table('dokumen_foto')->insert([
                        'dokumen_id' => $siapCetak->id, 'path_foto' => $pathFotoFinal, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $variables[$fileKey] = $pathFotoFinal;
                }
                $siapCetak->update(['variables' => $variables]);
            }

            $filePathFisik = $this->documentService->generate($siapCetak);
            $siapCetak->file_path = $filePathFisik;
            $siapCetak->save();

            DB::commit();

            $siapCetak->status = 'sudah_dicetak';

            return response()->json([
                'status' => 'success',
                'message' => 'Data draft & file Word berhasil dibuat!',
                'data' => $siapCetak
            ], 201);

        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Gagal menyimpan draft.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * 3. UPDATE DRAFT + Format Otomatis BA Kerusakan
     * (Menyimpan dalam format .docx agar jika draf diperbarui masih bisa diedit lagi)
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $dokumen = DokumenCetak::findOrFail($id);
            $variablesInput = $request->variables;
            $newVariables = is_string($variablesInput) ? json_decode($variablesInput, true) : (array)$variablesInput;
            $oldVariables = is_string($dokumen->variables) ? json_decode($dokumen->variables, true) : (array)$dokumen->variables;

            $currentTemplate = Template::find($dokumen->template_id);
            $templateNama = $currentTemplate ? strtolower($currentTemplate->nama) : '';
            
            $isBaPenyerahan = str_contains($templateNama, 'penyerahan') || str_contains(strtolower($request->nama_dokumen ?? $dokumen->nama_dokumen), 'penyerahan');
            $isBaKerusakan = str_contains($templateNama, 'kerusakan') || str_contains(strtolower($request->nama_dokumen ?? $dokumen->nama_dokumen), 'kerusakan');

            DB::table('dokumen_item')->where('dokumen_id', $id)->delete();

            $namaItem = $request->nama_item ?? $newVariables['item'] ?? $newVariables['nama_item'] ?? $newVariables['nama_alat'] ?? '';
            
            if (!empty($namaItem) || $isBaKerusakan) {
                if ($isBaKerusakan) {
                    // ✨ Kondisi khusus BA Kerusakan Saat Update ✨
                    $namaAlat = $newVariables['nama_alat'] ?? $namaItem ?? 'Alat';
                    $namaItem = $namaAlat;
                    
                    $merk = $newVariables['merk'] ?? '';
                    $model = $newVariables['model'] ?? '';
                    $noSeri = $newVariables['no_seri'] ?? '';
                    $spesifikasiFinal = "Merk :{$merk}, Model :{$model}, No Seri :{$noSeri}";
                    
                    $jumlahFinal = 1;
                    
                    $namaLaporan = $newVariables['nama_laporan'] ?? $newVariables['nama_pelapor'] ?? '-';
                    $namaPenanggung = $newVariables['nama_penanggung'] ?? $newVariables['nama_penanggung_jawab'] ?? '-';
                    $keteranganFinal = "{$namaAlat} mengalami kerusakan yang dilapor oleh {$namaLaporan} dan ditanggung jawabkan oleh {$namaPenanggung}";
                } elseif ($isBaPenyerahan) {
                    $spesifikasiFinal = $request->spesifikasi ?? $newVariables['spesifikasi'] ?? '';
                    $jumlahFinal = 1;
                    $namaPenyerah = $newVariables['nama_penyerah'] ?? '-';
                    $penerimaFinal = $newVariables['nama_penerima'] ?? '-';
                    $keteranganFinal = "{$namaItem} diserahkan oleh {$namaPenyerah} kepada {$penerimaFinal}";
                } else {
                    $spesifikasiFinal = $request->spesifikasi ?: "Merk: " . ($newVariables['merk'] ?? '-') . ", Model: " . ($newVariables['model'] ?? '-') . ", No Seri: " . ($newVariables['no_seri'] ?? '-');
                    $jumlahFinal = isset($newVariables['nama_alat']) ? 1 : ($request->jumlah ?? 1); 
                    $keteranganFinal = $request->keterangan_item ?? $newVariables['keterangan_kerusakan'] ?? '';
                }

                DB::table('dokumen_item')->insert([
                    'dokumen_id'  => $id,
                    'nama_item'   => $namaItem,
                    'spesifikasi' => $spesifikasiFinal,
                    'jumlah'      => $jumlahFinal, 
                    'keterangan'  => $keteranganFinal,
                    'created_at'  => now(), 
                    'updated_at'  => now(),
                ]);
            }

            if ($request->allFiles()) {
                $oldPhotos = DB::table('dokumen_foto')->where('dokumen_id', $id)->get();
                foreach ($oldPhotos as $p) { if (File::exists(public_path($p->path_foto))) { File::delete(public_path($p->path_foto)); } }
                DB::table('dokumen_foto')->where('dokumen_id', $id)->delete();

                foreach ($request->allFiles() as $fileKey => $file) {
                    if ($fileKey === 'foto_ttd') continue;
                    $nameFile = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $file->move(public_path('uploads/dokumen'), $nameFile);
                    $pathFotoFinal = 'uploads/dokumen/' . $nameFile;

                    DB::table('dokumen_foto')->insert([
                        'dokumen_id' => $id, 'path_foto' => $pathFotoFinal, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $newVariables[$fileKey] = $pathFotoFinal;
                }
            }

            if (isset($oldVariables['no'])) { $newVariables['no'] = $oldVariables['no']; }
            $dokumen->update([
                'nama_dokumen' => $request->nama_dokumen ?? $dokumen->nama_dokumen,
                'variables'    => $newVariables,
            ]);

            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'Draft berhasil diperbarui!', 'data' => $dokumen], 200);

        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Gagal diperbarui data draft.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * 4. Memperbarui status dokumen saat tombol PRINT ditekan.
     * ✨ SEKARANG MENANGKAP COPIES & PAGES UNTUK DIGANDAKAN DI PDF ✨
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $dokumen = DokumenCetak::findOrFail($id);
            
            // 📥 Tangkap input copies dan pages dari React
            $copies = (int) $request->input('copies', 1);
            $pages  = $request->input('pages', 'All');

            // 🚀 Meneruskan copies dan pages ke dalam generator PDF di Service
            $pdfRelativePath = $this->documentService->generatePdf($dokumen, $copies, $pages);

            return response()->json([
                'status' => 'success', 
                'message' => 'Status cetak diperbarui dan PDF siap dicetak!',
                'pdf_url' => asset($pdfRelativePath) // ⚡ Kirimkan URL PDF yang sudah digandakan halamannya ke React!
            ], 200);
            
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 5. Mengambil URL unduhan file Word (RE-GENERATE ON DOWNLOAD)
     * (Tetap mengunduh file berekstensi .docx untuk keperluan edit user)
     */
    public function download(Request $request, $id)
    {
        try {
            $antrean = DokumenCetak::findOrFail($id);
            $filePathFisik = $this->documentService->generate($antrean);

            $antrean->file_path = $filePathFisik;
            $antrean->save();

            return response()->json([
                'status' => 'success',
                'download_url' => asset($filePathFisik)
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan.'], 404);
        } catch (Throwable $e) { 
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}