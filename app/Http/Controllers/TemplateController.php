<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Template;
use PhpOffice\PhpWord\TemplateProcessor;
use ZipArchive;
use Exception;

class TemplateController extends Controller
{
    /**
     * 📤 Fungsi mengunggah file template Word baru
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
            $ukuran = $sizeInBytes >= 1048576 
                ? number_format($sizeInBytes / 1048576, 2) . ' MB' 
                : number_format($sizeInBytes / 1024, 2) . ' KB';

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
                'message' => 'Hore! Template berhasil disimpan berdasarkan jenisnya!',
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
     * 📋 Mengambil semua data untuk halaman File Template
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
     * 🗑️ Fungsi menghapus data & file fisik template secara permanen
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
     * 👁️ KUNCI SAKTI LIVE DOCUMENT PREVIEW & SCAN VARIABEL
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
                $zip = new ZipArchive();
                if ($zip->open($filePath) === true) {
                    $xmlContent = $zip->getFromName('word/document.xml');
                    $zip->close();

                    if ($xmlContent) {
                        // 1. Format HTML untuk Live Preview di Frontend
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

                        // 2. Bersihkan tag XML tersembunyi di dalam ${...} agar variabel tidak rusak/terpecah
                        $cleanXml = preg_replace_callback('/\$\{([^}]+)\}/', function($matches) {
                            return '${' . strip_tags($matches[1]) . '}';
                        }, $xmlContent);

                        preg_match_all('/\$\{([^}]+)\}/', $cleanXml, $matches);

                        if (!empty($matches[1])) {
                            $variables = array_values(array_unique($matches[1]));
                        }
                    }
                } else {
                    $templateProcessor = new TemplateProcessor($filePath);
                    $variables = array_values($templateProcessor->getVariables());
                    $textContent = "Gagal memproses visual layout dokumen via Zip Archive.";
                }
            } catch (Exception $e) {
                $variables = [];
                $textContent = "Terjadi eror saat membaca isi berkas template: " . $e->getMessage();
            }
        }

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'id' => $template->id,
            'nama' => $template->nama,
            'file_path' => $template->file_path,
            'ukuran' => $template->ukuran,
            'jenis_dokumen_id' => $template->jenis_dokumen_id, 
            'variables' => $variables,
            'data' => $variables, // Menyediakan key 'data' & 'variables' agar kompatibel dengan semua versi route
            'text_content' => $textContent 
        ], 200);
    }

    /**
     * 📌 FUNGSI OTOMATIS AMBIL TEMPLATE TERBARU BERDASARKAN JENISNYA
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

    /**
     * 🔍 Men-scan & mengembalikan variabel (${nama_variabel}) untuk React / Frontend
     */
    public function getVariables($id)
    {
        return $this->show($id);
    }
}