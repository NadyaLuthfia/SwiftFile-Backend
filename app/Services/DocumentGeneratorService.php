<?php

namespace App\Services;

use App\Models\DokumenCetak;
use App\Models\Template;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\DB;
use \Carbon\Carbon; // 🇮🇩 PENTING: Untuk penanganan format bulan teks bahasa Indonesia
use Throwable;
use setasign\Fpdi\Fpdi; // ⚡ Pustaka untuk memanipulasi halaman PDF

class DocumentGeneratorService 
{

    /**
     * 📝 LOGIKA UTAMA: Membuat file Word (.docx)
     */
    public function generate(DokumenCetak $antrean)
    {
        $templateMaster = Template::find($antrean->template_id);
        if (!$templateMaster) {
            throw new \Exception('Template tidak ditemukan.');
        }

        $templateMasterPath = public_path($templateMaster->file_path);
        if (!file_exists($templateMasterPath)) {
            throw new \Exception('File master template tidak ada.');
        }

        // Decode variabel dokumen
        $variables = $antrean->variables;
        if (is_string($variables)) {
            $variables = json_decode($variables, true);
        } else {
            $variables = (array)$variables;
        }

        $noUrut = $variables['no'] ?? '000';
        
        // -----------------------------------------------------------------
        // 🇮🇩 FIX LOGIC TANGGAL: Bikin format panjang & format pendek (dd/mm/yy)
        // -----------------------------------------------------------------
        $dateObj = $antrean->created_at ? Carbon::parse($antrean->created_at) : Carbon::now();
        
        $tahun = $dateObj->format('Y');
        $bulanAngka = (int)$dateObj->format('n');
        
        $romawiArr = [1=>'I', 2=>'II', 3=>'III', 4=>'IV', 5=>'V', 6=>'VI', 7=>'VII', 8=>'VIII', 9=>'IX', 10=>'X', 11=>'XI', 12=>'XII'];
        $bulanRomawi = $romawiArr[$bulanAngka] ?? 'I';

        // 1. Nama bulan alfabet teks biasa (Contoh: Juli)
        $bulanTeksBiasa = $dateObj->translatedFormat('F');

        // 2. Tanggal lengkap untuk bagian bawah dokumen (Contoh: 14 Juli 2026)
        $tanggalLengkap = $dateObj->translatedFormat('d F Y');

        // 3. Tanggal pendek untuk nomor/header atas (Contoh: 14/07/2026)
        $tanggalPendek = $dateObj->format('d/m/Y'); 
        // -----------------------------------------------------------------

        // Format nama file resmi seragam
        $namaTemplate = $templateMaster->nama;
        $namaFileFisik = "{$namaTemplate} - {$noUrut}-BTG-{$bulanRomawi}-BA-{$tahun}.docx";
        $namaFileFisik = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $namaFileFisik);

        $dirPath = public_path('documents');
        if (!is_dir($dirPath)) {
            @mkdir($dirPath, 0777, true);
        }
        $pathFile = $dirPath . '/' . $namaFileFisik; 

        // Copy dari master template ke tujuan output
        copy($templateMasterPath, $pathFile);

        // 🇮🇩 Deteksi jenis template untuk penanganan tanggal custom
        $isSuratTugas = str_contains(strtolower($namaTemplate), 'surat tugas');

        // Intersepsi file XML langsung sebelum PHPWord memproses variabelnya
        $zip = new \ZipArchive();
        if ($zip->open($pathFile) === true) {
            $xmlContent = $zip->getFromName('word/document.xml');
            
            // Satukan tag kontigu agar string variabel utuh tidak terpecah oleh bumbu XML Word
            $xmlContent = preg_replace_callback('/\$\{([^}]+)\}/', function($matches) {
                return '${' . strip_tags($matches[1]) . '}';
            }, $xmlContent);
            
            // FIX NOMOR SURAT: Deteksi ${bulan} khusus yang nempel di /BTG-.../
            $xmlContent = preg_replace_callback('/\/BTG-((?:<[^>]*>)*)\$\{bulan\}((?:<[^>]*>)*)\//', function($matches) use ($bulanRomawi) {
                return '/BTG-' . $matches[1] . $bulanRomawi . $matches[2] . '/';
            }, $xmlContent);
            
            // 🇮🇩 DETEKSI & PERUBAHAN TANGGAL PERTAMA SECARA PRESISI (Limit: 1)
            if ($isSuratTugas) {
                $xmlContent = preg_replace('/\$\{tanggal\}/', '${tanggal_singkat}', $xmlContent, 1);
            } else {
                $xmlContent = preg_replace('/\$\{tanggal\}/', '${tanggal_hari_saja}', $xmlContent, 1);
                $xmlContent = preg_replace('/\$\{bulan\}/', '${bulan_pembuka}', $xmlContent, 1);
                $xmlContent = preg_replace('/\$\{tahun\}/', '${tahun_pembuka}', $xmlContent, 1);
            }
            
            $zip->addFromString('word/document.xml', $xmlContent);
            $zip->close();
        }

        // Jalankan PHPWord TemplateProcessor
        $templateProcessor = new TemplateProcessor($pathFile);

        // Daftarkan penampung variabel tanggal khusus hasil interceptor XML
        $templateProcessor->setValue('tanggal_singkat', $tanggalPendek);
        $templateProcessor->setValue('tanggal_hari_saja', $dateObj->format('d'));
        $templateProcessor->setValue('bulan_pembuka', $bulanTeksBiasa);
        $templateProcessor->setValue('tahun_pembuka', $tahun);

        // Variabel utama sisa dokumen
        $templateProcessor->setValue('tanggal', $tanggalLengkap);
        $templateProcessor->setValue('bulan', $bulanTeksBiasa);
        $templateProcessor->setValue('tahun', $tahun);

        // Isi variabel text biasa
        if (is_array($variables)) {
            foreach ($variables as $key => $value) {
                if (str_starts_with($key, 'foto_')) {
                    continue;
                }

                if ($value === 'null' || is_null($value)) {
                    $value = '';
                }

                if ((str_contains($key, 'berangkat') || str_contains($key, 'tiba')) && !empty($value)) {
                    try {
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                            $value = Carbon::parse($value)->format('d/m/Y');
                        }
                    } catch (\Exception $e) {
                        // Aman
                    }
                }

                $templateProcessor->setValue($key, $value);
            }
        }

        // Suntik data barang / item ke dalam tabel Word
        $dbItems = DB::table('dokumen_item')->where('dokumen_id', $antrean->id)->get();
        if ($dbItems->isNotEmpty()) {
            $first = $dbItems->first();
            $templateProcessor->setValue('item', $first->nama_item);
            $templateProcessor->setValue('spesifikasi', $first->spesifikasi);
            $templateProcessor->setValue('jumlah', $first->jumlah);
        } else {
            $templateProcessor->setValue('item', '-');
            $templateProcessor->setValue('spesifikasi', '-');
            $templateProcessor->setValue('jumlah', '-');
        }

        // -----------------------------------------------------------------
        // ✨ LOGIKA FOTO: UKURAN DINAMIS (RESPONSIF BERDASARKAN JUMLAH FOTO)
        // -----------------------------------------------------------------
        $dbPhotos = DB::table('dokumen_foto')->where('dokumen_id', $antrean->id)->get();
        
        // 1. Filter & kumpulkan dulu foto yang filenya beralamat valid & ada fisiknya
        $validPhotos = [];
        foreach ($dbPhotos as $p) {
            if (!empty($p->path_foto) && file_exists(public_path($p->path_foto))) {
                $validPhotos[] = $p;
            }
        }
        
        $photoCount = count($validPhotos);

        // 2. Kalkulasi ukuran otomatis berdasarkan jumlah foto yang masuk
        if ($photoCount <= 1) {
            $width = 560;  // 1 Foto: Lebar maksimal mepet margin
            $height = 350;
        } elseif ($photoCount == 2) {
            $width = 460;  // 2 Foto: Mengecil sedikit agar muat vertikal 1 halaman
            $height = 250;
        } elseif ($photoCount == 3) {
            $width = 280;  // 3 Foto: Lebih kecil lagi
            $height = 200;
        } else {
            $width = 210;  // 4 Foto: Ukuran terkecil aman agar tidak ganti halaman
            $height = 170;
        }

        $filledKeys = [];
        $allKeys = ['foto_1', 'foto_2', 'foto_3', 'foto_4'];

        // 3. Suntik gambar dengan ukuran dinamis yang sudah dihitung
        foreach ($validPhotos as $index => $p) {
            if ($index >= 4) break; // Batasi maksimal 4 foto sesuai kuota template
            
            $keyName = 'foto_' . ($index + 1);
            $templateProcessor->setImageValue($keyName, [
                'path'   => public_path($p->path_foto),
                'width'  => $width,
                'height' => $height,
                'ratio'  => true // Mengunci aspek rasio asli agar gambar tidak penyet/tarik
            ]);
            $filledKeys[] = $keyName;
        }

        // Bersihkan sisa tag foto yang kosong/tidak terisi
        foreach ($allKeys as $key) { 
            if (!in_array($key, $filledKeys)) {
                $templateProcessor->setValue($key, ''); 
            }
        }
        // -----------------------------------------------------------------

        $templateProcessor->saveAs($pathFile);

        return 'documents/' . $namaFileFisik;
    }

    /**
     * 🖨️ FUNGSI PDF: Konversi Word ke PDF + Filter Halaman + Penggandaan Rangkap
     */
    public function generatePdf(DokumenCetak $antrean, int $copies = 1, string $pages = 'All')
    {
        $docxRelativePath = $antrean->file_path;
        if (empty($docxRelativePath) || !file_exists(public_path($docxRelativePath))) {
            $docxRelativePath = $this->generate($antrean);
            $antrean->file_path = $docxRelativePath;
            $antrean->save();
        }

        $docxAbsPath = public_path($docxRelativePath);
        $dirPath = dirname($docxAbsPath);

        $pdfFileName = pathinfo($docxAbsPath, PATHINFO_FILENAME) . '.pdf';
        $pdfAbsPath = $dirPath . '/' . $pdfFileName;
        $pdfRelativePath = 'documents/' . $pdfFileName;

        if (!file_exists($pdfAbsPath)) {
            $sofficePath = '';

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $portablePath1 = base_path('libreoffice/program/soffice.exe');
                $portablePath2 = base_path('libreoffice/App/libreoffice/program/soffice.exe');
                $installedPath = 'C:\\Program Files\\LibreOffice\\program\\soffice.exe';

                if (file_exists($portablePath1)) {
                    $sofficePath = $portablePath1;
                } elseif (file_exists($portablePath2)) {
                    $sofficePath = $portablePath2;
                } elseif (file_exists($installedPath)) {
                    $sofficePath = $installedPath;
                } else {
                    throw new \Exception('Mesin LibreOffice tidak ditemukan. Pastikan terinstal atau ada di folder backend.');
                }
            } else {
                $linuxPath = '/usr/bin/libreoffice';
                $sofficePath = file_exists($linuxPath) ? $linuxPath : 'soffice';
            }

            if ($sofficePath === 'soffice') {
                $cmd = "soffice --headless --convert-to pdf --outdir " . escapeshellarg($dirPath) . " " . escapeshellarg($docxAbsPath);
            } else {
                $escapedSoffice = '"' . $sofficePath . '"';
                $cmd = "{$escapedSoffice} --headless --convert-to pdf --outdir " . escapeshellarg($dirPath) . " " . escapeshellarg($docxAbsPath);
            }

            exec($cmd);
        }

        if (!file_exists($pdfAbsPath)) {
            throw new \Exception('Gagal melakukan konversi awal ke PDF.');
        }

        $isAllPages = strtolower(trim($pages)) === 'all' || empty($pages);

        if ($copies > 1 || !$isAllPages) {
            $pagesClean = preg_replace('/[^0-9,-]/', '', $pages);
            if (empty($pagesClean)) {
                $pagesClean = 'all';
            }

            $customFileName = pathinfo($docxAbsPath, PATHINFO_FILENAME) . "_c{$copies}_p{$pagesClean}.pdf";
            $customAbsPath = $dirPath . '/' . $customFileName;
            $customRelativePath = 'documents/' . $customFileName;

            if (file_exists($customAbsPath)) {
                return $customRelativePath;
            }

            try {
                $fpdi = new Fpdi();
                $pageCount = $fpdi->setSourceFile($pdfAbsPath);
                $selectedPages = $this->parsePages($pages, $pageCount);

                for ($c = 0; $c < $copies; $c++) {
                    foreach ($selectedPages as $pageNo) {
                        $templateId = $fpdi->importPage($pageNo);
                        $size = $fpdi->getTemplateSize($templateId);
                        $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
                        
                        $fpdi->AddPage($orientation, [$size['width'], $size['height']]);
                        $fpdi->useTemplate($templateId);
                    }
                }

                $fpdi->Output('F', $customAbsPath);
                return $customRelativePath;

            } catch (\Throwable $e) {
                logger()->error('Gagal memproses filter/penggandaan PDF: ' . $e->getMessage());
                return $pdfRelativePath;
            }
        }

        return $pdfRelativePath;
    }

    /**
     * 🎯 HELPER: Memecah input string halaman menjadi array angka
     */
    private function parsePages(string $pagesStr, int $maxPages): array
    {
        if (strtolower(trim($pagesStr)) === 'all' || empty($pagesStr)) {
            return range(1, $maxPages);
        }

        $selectedPages = [];
        $pagesStr = str_replace(' ', '', $pagesStr);
        $parts = explode(',', $pagesStr);

        foreach ($parts as $part) {
            if (str_contains($part, '-')) {
                $range = explode('-', $part);
                if (count($range) === 2) {
                    $start = (int)$range[0];
                    $end = (int)$range[1];
                    $start = max(1, min($start, $maxPages));
                    $end = max(1, min($end, $maxPages));
                    
                    if ($start <= $end) {
                        for ($i = $start; $i <= $end; $i++) {
                            $selectedPages[] = $i;
                        }
                    }
                }
            } else {
                $page = (int)$part;
                if ($page >= 1 && $page <= $maxPages) {
                    $selectedPages[] = $page;
                }
            }
        }

        $selectedPages = array_unique($selectedPages);
        sort($selectedPages);

        return empty($selectedPages) ? range(1, $maxPages) : $selectedPages;
    }
}