<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB; // 🚨 Facade DB untuk insert ke tabel utama & item
use \Carbon\Carbon;                 // 🚨 Untuk format tanggal Indonesia
use App\Models\Template; // 🚨 Model Template
use App\Models\Draft;    // 🚨 Model Draft (Tetap disimpan jika masih dipakai untuk hitung nomor)
use PhpOffice\PhpWord\TemplateProcessor; // 🚨 Scan & Edit file Word template

class TemplateController extends Controller
{
    /**
     * 🌟 Fungsi mengunggah file template Word baru
     */
    public function upload(Request $request)
    {
        $request->validate([
            'nama' => 'required|string|max:255',
            'file_template' => 'required|file|max:10240', 
            'jenis_dokumen_id' => 'required|integer', 
        ]);

        if ($request->hasFile('file_template')) {
            $file = $request->file('file_template');
            $originalName = $file->getClientOriginalName();
            $namaTemplateCustom = $request->input('nama');
            
            $sizeInBytes = $file->getSize();
            if ($sizeInBytes >= 1048576) {
                $ukuran = number_format($sizeInBytes / 1048576, 2) . ' MB';
            } else {
                $ukuran = number_format($sizeInBytes / 1024, 2) . ' KB';
            }

            $cleanFileName = time() . '_' . str_replace(' ', '_', $originalName);
            $destinationPath = public_path('templates');
            $file->move($destinationPath, $cleanFileName);

            $directPath = '/templates/' . $cleanFileName;

            $template = Template::create([
                'nama' => $namaTemplateCustom, 
                'file_path' => $directPath, 
                'ukuran' => $ukuran, 
                'jenis_dokumen_id' => $request->input('jenis_dokumen_id'), 
            ]);

            return response()->json([
                'success' => true,
                'message' => '🎉 Hore! Template berhasil disimpan berdasarkan jenisnya!',
                'file_name' => $namaTemplateCustom,
                'jenis_dokumen_id' => $template->jenis_dokumen_id,
                'path' => $directPath
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'File tidak ditemukan.'
        ], 400);
    }

    /**
     * 🌟 Mengambil semua data untuk halaman File Template
     */
    public function index()
    {
        $templates = Template::latest()->get(); 

        return response()->json([
            'success' => true,
            'data' => $templates
        ], 200);
    }

    /**
     * 🌟 Fungsi menghapus data & file fisik template secara permanen
     */
    public function destroy($id)
    {
        $template = Template::find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Data template tidak ditemukan.'
            ], 404);
        }

        $file_path = public_path($template->file_path);

        if (file_exists($file_path)) {
            unlink($file_path); 
        }

        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Template berhasil dihapus dari server dan database!'
        ], 200);
    }

    /**
     * 🌟 KUNCI SAKTI LIVE DOCUMENT PREVIEW (Membaca file spesifik)
     */
    public function show($id)
    {
        $template = Template::find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Template tidak ditemukan.'
            ], 404);
        }

        $filePath = public_path($template->file_path);
        $variables = []; 
        $textContent = ''; 

        if (file_exists($filePath) && pathinfo($filePath, PATHINFO_EXTENSION) === 'docx') {
            try {
                $zip = new \ZipArchive();
                if ($zip->open($filePath) === true) {
                    $xmlContent = $zip->getFromName('word/document.xml');
                    $zip->close();

                    $htmlFormatted = str_replace(
                        [
                            '</w:p>', '<w:br/>', '<w:br />', 
                            '<w:tbl>', '</w:tbl>', 
                            '<w:tr>', '</w:tr>', 
                            '<w:tc>', '</w:tc>'
                        ], 
                        [
                            '<br />', '<br />', '<br />', 
                            '<table>', '</table>', 
                            '<tr>', '</tr>', 
                            '<td>', '</td>'
                        ], 
                        $xmlContent
                    );
                    
                    $textContent = strip_tags($htmlFormatted, '<br><table><tr><td><th><tbody><thead>');

                    preg_match_all('/\$\{([^}]+)\}/', strip_tags($xmlContent), $matches);

                    $uniqueVars = array_unique($matches[1]);
                    $variables = array_values($uniqueVars);
                    
                } else {
                    $templateProcessor = new TemplateProcessor($filePath);
                    $variables = array_values($templateProcessor->getVariables());
                    $textContent = "Gagal memproses visual layout dokumen via Zip Archive.";
                }
            } catch (\Exception $e) {
                $variables = [];
                $textContent = "Terjadi eror saat membaca isi berkas template: " . $e->getMessage();
            }
        }

        return response()->json([
            'success' => true,
            'id' => $template->id,
            'nama' => $template->nama,
            'file_path' => $template->file_path,
            'ukuran' => $template->ukuran,
            'jenis_dokumen_id' => $template->jenis_dokumen_id, 
            'variables' => $variables,
            'text_content' => $textContent 
        ], 200);
    }

    /**
     * 🟢 FUNGSI OTOMATIS AMBIL TEMPLATE TERBARU BERDASARKAN JENISNYA
     * Berfungsi memanggil template terbaru di halaman draf agar user tidak pusing memilih versi.
     */
    public function getLatestByJenis($jenis_dokumen_id)
    {
        $template = Template::where('jenis_dokumen_id', $jenis_dokumen_id)
                            ->latest()
                            ->first();

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Belum ada file template yang diunggah untuk jenis dokumen ini.'
            ], 404);
        }

        return $this->show($template->id);
    }

    public function getVariables($id)
    {
        return $this->show($id);
    }

    /**
     * 🚀 SELESAI & KIRIM KE ANTREAN (OTOMATIS MASUK ARSIP & SIAP CETAK)
     */
    public function generateDocx(Request $request)
    {
        $request->validate([
            'template_id' => 'required',
            'variables'   => 'required|array'
        ]);

        try {
            $template = Template::findOrFail($request->template_id);
            $templatePath = public_path($template->file_path);

            if (!file_exists($templatePath)) {
                return response()->json(['message' => 'Aduh sayang, file template aslinya gak ditemukan di server.'], 404);
            }

            $outputDir = public_path('templates/outputs');
            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0777, true);
            }

            $pureFileName = basename($template->file_path);
            $fileName = 'Cetak_' . time() . '_' . $pureFileName;
            $outputPath = $outputDir . '/' . $fileName;

            // -----------------------------------------------------------------
            // 🌟 AMBIL DATA & FIX PREFIX NO_ORDER DI SINI
            // -----------------------------------------------------------------
            $variablesData = $request->variables;

            if (isset($variablesData['no_order']) && !empty($variablesData['no_order'])) {
                if (!str_starts_with($variablesData['no_order'], '590101')) {
                    $variablesData['no_order'] = '590101' . $variablesData['no_order'];
                }
            }

            // -----------------------------------------------------------------
            // 📂 INTERCEPTOR OTOMATIS: Set bidang_laporan Berdasarkan nama_laporan
            // -----------------------------------------------------------------
            if (isset($variablesData['nama_laporan']) && !empty($variablesData['nama_laporan'])) {
                
                // Array pencocokan nama laporan -> nama bidang kerja
                // Silakan sesuaikan teks value dropdown di sisi kiri (Key) dengan form React kamu
                $listBidangLaporan = [
                    'Laporan Kerusakan PC / Printer' => 'Teknologi Informasi (TI)',
                    'Laporan Kerusakan AC & Listrik' => 'Bagian Umum & Sarpras',
                    'Laporan Kerusakan Mobil Dinas'  => 'Bagian Operasional',
                    'Laporan Dokumen Rusak'          => 'Bagian Administrasi / Kearsipan',
                ];

                // Jika nama_laporan terdaftar di array, isi bidang_laporan secara otomatis
                $variablesData['bidang_laporan'] = $listBidangLaporan[$variablesData['nama_laporan']] ?? 'Bagian Umum';
            }
            // -----------------------------------------------------------------

            $tanggalInput = $variablesData['tanggal_dokumen'] ?? $variablesData['tanggal_input'] ?? date('d/m/Y'); 
            
            try {
                if (str_contains($tanggalInput, '/')) {
                    $carbonTgl = Carbon::createFromFormat('d/m/Y', $tanggalInput);
                } else {
                    $carbonTgl = Carbon::parse($tanggalInput);
                }
            } catch (\Exception $e) {
                $carbonTgl = now();
            }

            $tahunOtomatis = $carbonTgl->format('Y');
            $bulanAngka = $carbonTgl->format('n'); 
            
            $daftarRomawi = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
            $bulanRomawiOtomatis = $daftarRomawi[$bulanAngka] ?? 'I';
            $bulanTeksBiasa = $carbonTgl->locale('id')->translatedFormat('F');

            // Pembersihan internal tag XML dokumen Word
            $zip = new \ZipArchive();
            if ($zip->open($templatePath) === true) {
                $xmlContent = $zip->getFromName('word/document.xml');
                
                $xmlContent = preg_replace_callback('/\$\{([^}]+)\}/', function($matches) {
                    return '${' . strip_tags($matches[1]) . '}';
                }, $xmlContent);

                $xmlContent = preg_replace('/\/BTG-\s*\$\{bulan\}\s*\//', '/BTG-' . $bulanRomawiOtomatis . '/', $xmlContent);
                
                copy($templatePath, $outputPath);
                
                if ($zip->open($outputPath) === true) {
                    $zip->addFromString('word/document.xml', $xmlContent);
                    $zip->close();
                }
            } else {
                copy($templatePath, $outputPath);
            }

            // Inisialisasi PHPWord TemplateProcessor
            $templateProcessor = new TemplateProcessor($outputPath);

            $noUrutBaru = Draft::whereYear('created_at', $tahunOtomatis)->count() + 1;
            $noOtomatis = str_pad($noUrutBaru, 3, '0', STR_PAD_LEFT);
            $nomorDokumenPenuh = "k - {$noOtomatis}/BTG-{$bulanRomawiOtomatis}/BA/{$tahunOtomatis}";
            $tanggalFullHariItu = $carbonTgl->locale('id')->translatedFormat('d F Y');

            $variablesData['no'] = $noOtomatis;
            $variablesData['bulan'] = $bulanTeksBiasa;       
            $variablesData['tanggal'] = $tanggalFullHariItu; 
            $variablesData['tahun'] = $tahunOtomatis;

            $barang            = $variablesData['item'] ?? $variablesData['barang'] ?? '';
            $spesifikasi       = $variablesData['spesifikasi'] ?? '';
            $jumlah            = $variablesData['jumlah'] ?? 0;
            $kalimatKeterangan = $variablesData['keterangan'] ?? '';

            // Inject data dari form React ke template Word
            foreach ($variablesData as $key => $value) {
                $safeValue = $value ?? ''; 

                // ✨ INTERCEPTOR PINTAR FORMAT TANGGAL (dd/mm/yyyy)
                if (str_contains($key, 'tanggal') && !empty($safeValue)) {
                    try {
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $safeValue)) {
                            if (str_contains($key, 'berangkat') || str_contains($key, 'tiba')) {
                                $safeValue = Carbon::parse($safeValue)->format('d/m/Y');
                            } else {
                                $safeValue = Carbon::parse($safeValue)->locale('id')->translatedFormat('d F Y');
                            }
                        }
                    } catch (\Exception $e) {
                        // Aman
                    }
                }

                $templateProcessor->setValue($key, $safeValue);
            }

            $templateProcessor->saveAs($outputPath);

            // Simpan ke database utama
            $idDokumenBaru = DB::table('dokumen')->insertGetId([
                'template_id'      => $template->id,
                'user_id'          => auth()->id() ?? 3, 
                'jenis_dokumen_id' => $template->jenis_dokumen_id,
                'nomor_dokumen'    => $nomorDokumenPenuh, 
                'jenis'            => $variablesData['jenis'] ?? 'kerusakan',
                'status'           => 'arsip', 
                'tanggal_dokumen'  => $carbonTgl->format('Y-m-d'), 
                'penyerah_id'      => $variablesData['penyerah_id'] ?? 4, 
                'penerima_id'      => $variablesData['penerima_id'] ?? 4,
                'file_sblm_print'  => 'templates/outputs/' . $fileName, 
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            DB::table('dokumen_item')->insert([
                'dokumen_id'  => $idDokumenBaru, 
                'nama_item'   => $barang,       
                'spesifikasi' => $spesifikasi,  
                'jumlah'      => $jumlah,       
                'keterangan'  => $kalimatKeterangan,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => '🎉 Mantap! Dokumen berhasil diarsip dengan format baru!'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses pengarsipan dokumen.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}