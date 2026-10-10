<?php

namespace App\Services;

use App\Models\DokumenCetak;
use App\Models\Template;
use PhpOffice\PhpWord\TemplateProcessor;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Carbon\Carbon;
use ZipArchive;
use Exception;
use Throwable;

class DocumentGeneratorService 
{
    public function generate(DokumenCetak $antrean, int $batasFoto = 0, int $kolomPerBaris = 4): string
    {
        $templateMaster = Template::find($antrean->template_id);
        if (!$templateMaster) {
            throw new Exception('Template tidak ditemukan.');
        }

        $templateMasterPath = public_path($templateMaster->file_path);
        if (!file_exists($templateMasterPath)) {
            throw new Exception('File master template tidak ada.');
        }

        // 1. Extract JSON Variables dari model DokumenCetak
        $variables = $antrean->variables;
        if (is_string($variables)) {
            $variables = json_decode($variables, true) ?? [];
        } else {
            $variables = (array)$variables;
        }

        $rincianBarang = $variables['rincian_barang'] ?? $variables['items'] ?? $variables['rincian'] ?? [];
        $firstItem     = (!empty($rincianBarang) && is_array($rincianBarang)) ? (array)$rincianBarang[0] : [];

        // =========================================================================
        // AMBIL DATA & FORMAT NAMA DOKUMEN
        // =========================================================================
        $namaBarang = $variables['nama_barang'] ?? '';
        if (empty($namaBarang) && !empty($firstItem)) {
            $namaBarang = $firstItem['barang'] ?? $firstItem['nama_barang'] ?? $firstItem['nama_item'] ?? '';
        }

        $namaPenyerah = $variables['nama_penyerah'] ?? $variables['penyerah'] ?? '';

        $namaTemplate = $templateMaster->nama;
        $namaLower    = strtolower($namaTemplate);
        $isSuratTugas = str_contains($namaLower, 'surat tugas');
        $isTorBarang  = str_contains($namaLower, 'tor barang');
        $isTor        = $isTorBarang || str_contains($namaLower, 'tor');

        $noUrut = $variables['no'] ?? '000';

        if ($isTorBarang) {
            $parts = ['TOR Barang'];
            if (!empty($namaBarang)) {
                $parts[] = $namaBarang;
            }
            if (!empty($namaPenyerah)) {
                $parts[] = $namaPenyerah;
            }
            $namaDokumen = implode(' - ', $parts);

            $antrean->nama_dokumen = $namaDokumen;
            $antrean->save();
        } else {
            $namaDokumen = $antrean->nama_dokumen ?? "{$templateMaster->nama} - {$noUrut}";
        }

        // =========================================================================
        // PERBAIKAN TANGGAL: Prioritaskan ambil dari 'tanggal_input' (tanggal penuh)
        // =========================================================================
        $rawTanggal = $variables['tanggal_input'] ?? $variables['tanggal_dokumen'] ?? $variables['tanggal'] ?? null;
        $dateObj = null;

        if (!empty($rawTanggal)) {
            try {
                // Cegah angka 1-2 digit (seperti '6' atau '12') diparse langsung oleh Carbon
                if (is_numeric($rawTanggal) && strlen((string)$rawTanggal) <= 2) {
                    throw new Exception('Ini cuma angka hari, bukan tanggal lengkap');
                }
                $dateObj = Carbon::parse($rawTanggal);
            } catch (Throwable $e) {
                // Kalau format berantakan, kembalikan jadi null
                $dateObj = null; 
            }
        }

        // Kalau form tanggal kosong/error, kembali pakai tanggal sistem
        if (!$dateObj) {
            $dateObj = $antrean->created_at ? Carbon::parse($antrean->created_at) : Carbon::now();
        }

        $tahun = $dateObj->format('Y');
        $bulanAngka = (int)$dateObj->format('n');

        $bulanRomawi = DocumentNumberService::getBulanRomawi($bulanAngka);
        $bulanTeksBiasa = DocumentNumberService::getBulanIndo($bulanAngka);
        $tanggalLengkap = $dateObj->format('d') . ' ' . $bulanTeksBiasa . ' ' . $tahun;
        $tanggalPendek = $dateObj->format('d/m/Y');

        $namaFileFisik = "{$namaDokumen}.docx";
        $namaFileFisik = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $namaFileFisik);

        $dirPath = public_path('documents');
        if (!is_dir($dirPath)) {
            @mkdir($dirPath, 0777, true);
        }
        $pathFile = $dirPath . '/' . $namaFileFisik;

        copy($templateMasterPath, $pathFile);

        // =========================================================================
        // PROSES PEMBERSIHAN TAG XML & MACRO REPLACEMENT
        // =========================================================================
        $zip = new ZipArchive();
        if ($zip->open($pathFile) === true) {
            $xmlContent = $zip->getFromName('word/document.xml');

            if ($xmlContent) {
                $xmlContent = preg_replace_callback('/\$[^{}<]*\{[^{}]*\}/s', function($matches) {
                    return strip_tags($matches[0]);
                }, $xmlContent);

                $xmlContent = preg_replace_callback('/\$\{([^}]+)\}/', function($matches) {
                    return '${' . trim(strip_tags($matches[1])) . '}';
                }, $xmlContent);

                $xmlContent = preg_replace_callback('/\/BTG-((?:<[^>]*>)*)\$\{bulan\}((?:<[^>]*>)*)\//', function($matches) use ($bulanRomawi) {
                    return '/BTG-' . $matches[1] . $bulanRomawi . $matches[2] . '/';
                }, $xmlContent);

                if ($isSuratTugas) {
                    $xmlContent = preg_replace('/\$\{tanggal\}/', '${tanggal_singkat}', $xmlContent, 1);
                } else {
                    $xmlContent = preg_replace('/\$\{tanggal\}/', '${tanggal_hari_saja}', $xmlContent, 1);
                    $xmlContent = preg_replace('/\$\{bulan\}/', '${bulan_pembuka}', $xmlContent, 1);
                    $xmlContent = preg_replace('/\$\{tahun\}/', '${tahun_pembuka}', $xmlContent, 1);
                }

                $zip->addFromString('word/document.xml', $xmlContent);
            }
            $zip->close();
        }

        $templateProcessor = new TemplateProcessor($pathFile);

        $templateProcessor->setValue('tanggal_singkat', $tanggalPendek);
        $templateProcessor->setValue('tanggal_hari_saja', $dateObj->format('d'));
        $templateProcessor->setValue('bulan_pembuka', $bulanTeksBiasa);
        $templateProcessor->setValue('tahun_pembuka', $tahun);
        $templateProcessor->setValue('tanggal', $tanggalLengkap);
        $templateProcessor->setValue('bulan', $bulanTeksBiasa);
        $templateProcessor->setValue('tahun', $tahun);

        // =========================================================================
        // 1. PROSES TABEL RINCIAN MULTI-ROW
        // =========================================================================
        if (!empty($rincianBarang) && is_array($rincianBarang)) {
            try {
                $templateProcessor->cloneRow('no', count($rincianBarang));
            } catch (Throwable $e) {
                try {
                    $templateProcessor->cloneRow('barang', count($rincianBarang));
                } catch (Throwable $e) {}
            }

            foreach ($rincianBarang as $index => $item) {
                $r = $index + 1;
                $arr = (array)$item;

                $itemBarang = $arr['barang'] ?? $arr['nama_barang'] ?? $arr['nama_item'] ?? '';
                $qtyItem    = $arr['qty'] ?? $arr['jumlah'] ?? 1;
                $satuanItem = $arr['satuan'] ?? 'Pcs';
                $hSatuan    = $arr['harga_satuan'] ?? $arr['price'] ?? $arr['harga'] ?? 1;
                $totalItem  = $arr['total'] ?? $arr['jumlah_harga'] ?? ($qtyItem * (is_numeric($hSatuan) ? $hSatuan : 1));

                // FIX: Ubah format nomor biar jadi 1, 2, 3 murni
                $noFormatted = $r;

                $templateProcessor->setValue("no#{$r}", $noFormatted);
                $templateProcessor->setValue("barang#{$r}", $itemBarang);
                $templateProcessor->setValue("qty#{$r}", $qtyItem);
                $templateProcessor->setValue("satuan#{$r}", $satuanItem);
                $templateProcessor->setValue("harga_satuan#{$r}", is_numeric($hSatuan) ? number_format((float)$hSatuan, 0, ',', '.') : $hSatuan);
                $templateProcessor->setValue("total#{$r}", is_numeric($totalItem) ? number_format((float)$totalItem, 0, ',', '.') : $totalItem);
            }
        }

        // =========================================================================
        // 2. PROSES HEADER & VARIABEL LAIN
        // =========================================================================
        // Set nomor dokumen di header ${no} setelah tabel rincian selesai di-clone
        $templateProcessor->setValue('no', $noUrut);

        $templateProcessor->setValue('nama_barang', $variables['nama_barang'] ?? $firstItem['barang'] ?? $firstItem['nama_barang'] ?? '');
        $templateProcessor->setValue('merk', $variables['merk'] ?? '');
        $templateProcessor->setValue('type', $variables['type'] ?? $variables['tipe'] ?? '');
        $templateProcessor->setValue('persyaratan_tambahan', $variables['persyaratan_tambahan'] ?? $firstItem['spesifikasi'] ?? '');
        $templateProcessor->setValue('jumlah', $variables['jumlah'] ?? $firstItem['jumlah'] ?? $firstItem['qty'] ?? '1');

        $templateProcessor->setValue('spesifikasi', $variables['spesifikasi'] ?? $firstItem['spesifikasi'] ?? '');

        $rincianBiayaVal = $variables['rincian_biaya'] ?? $variables['rincian_komponen_biaya'] ?? $variables['rincian'] ?? '';
        if (is_array($rincianBiayaVal)) {
            $rincianBiayaVal = implode(', ', array_filter($rincianBiayaVal, 'is_scalar'));
        }
        $templateProcessor->setValue('rincian_biaya', $rincianBiayaVal);
        $templateProcessor->setValue('rincian_biaya ', $rincianBiayaVal);

        $hSatuanSec2 = $variables['harga_satuan'] ?? $firstItem['harga_satuan'] ?? null;
        $jHargaSec2  = $variables['jumlah_harga'] ?? $firstItem['jumlah_harga'] ?? $variables['total'] ?? null;

        $templateProcessor->setValue('harga_satuan', is_numeric($hSatuanSec2) ? number_format((float)$hSatuanSec2, 0, ',', '.') : ($hSatuanSec2 ?? ''));
        $templateProcessor->setValue('jumlah_harga', is_numeric($jHargaSec2) ? number_format((float)$jHargaSec2, 0, ',', '.') : ($jHargaSec2 ?? ''));

        // =========================================================================
        // 3. EMBED FOTO KE WORD
        // =========================================================================
        $rawPhotos   = $variables['foto'] ?? $variables['photos'] ?? $variables['lampiran_foto'] ?? [];
        $validPhotos = $this->filterValidPhotos($rawPhotos, $batasFoto);

        // FIX: Lempar juga $rincianBarang ke dalam fungsi foto biar namanya bisa ditarik
        $this->injectLampiranFotoBarang($templateProcessor, $validPhotos, $rincianBarang);
        $this->injectTorBarangVariables($templateProcessor, $variables);

        // =========================================================================
        // 3.5. GENERATE & EMBED QR CODE SCAN PERSETUJUAN (${scan} / ${SCAN})
        // =========================================================================
        $tempQrPath = null;
        try {
            $namaCabang = $variables['kepala_cabang'] ?? $variables['KEPALA_CABANG'] ?? '-';
            $namaBidang = $variables['kepala_bidang'] ?? $variables['KEPALA_BIDANG'] ?? '-';
            $jabatanBidang = $variables['jabatan_bidang'] ?? $variables['JABATAN_BIDANG'] ?? $variables['jabatan'] ?? '-';

            $teksScan = "Disetujui oleh {$namaBidang} sebagai {$jabatanBidang} dan oleh {$namaCabang} sebagai Kepala Cabang";

            $tempQrPath = sys_get_temp_dir() . '/temp_qr_' . uniqid() . '.png';

            if (class_exists(\SimpleSoftwareIO\QrCode\Facades\QrCode::class)) {
                QrCode::format('png')->size(180)->margin(1)->generate($teksScan, $tempQrPath);
            } else {
                $qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=" . urlencode($teksScan);
                $qrContent = @file_get_contents($qrApiUrl);
                if ($qrContent) {
                    file_put_contents($tempQrPath, $qrContent);
                }
            }

            if ($tempQrPath && file_exists($tempQrPath)) {
                try {
                    $templateProcessor->setImageValue('scan', [
                        'path'   => $tempQrPath,
                        'width'  => 210,
                        'height' => 210,
                        'ratio'  => true
                    ]);
                } catch (Throwable $e) {}

                try {
                    $templateProcessor->setImageValue('SCAN', [
                        'path'   => $tempQrPath,
                        'width'  => 85,
                        'height' => 85,
                        'ratio'  => true
                    ]);
                } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {
            // Abaikan jika tag ${scan} tidak ada di template
        }

        // =========================================================================
        // 4. SET VALUE UNTUK VARIABEL SISA
        // =========================================================================
        $protectedKeys = [
            'no', 'nama_barang', 'barang', 'spesifikasi', 'qty', 'jumlah', 'satuan',
            'harga_satuan', 'total', 'jumlah_harga', 'barang_kiri', 'barang_kanan',
            'foto_kiri', 'foto_kanan', 'barang_1', 'barang_2', 'rincian_barang', 'items', 'rincian', 'foto', 'photos', 'lampiran_foto',
            'scan', 'SCAN'
        ];

        foreach ($variables as $key => $value) {
            if (str_starts_with($key, 'foto_')) continue;
            if (in_array($key, $protectedKeys)) continue;

            if ($value === 'null' || is_null($value)) {
                $value = '';
            }

            if (is_array($value)) {
                if (count($value) > 0 && is_array(reset($value))) continue;
                $value = implode(', ', array_filter($value, 'is_scalar'));
            }

            if ((str_contains($key, 'berangkat') || str_contains($key, 'tiba')) && !empty($value)) {
                try {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $value = Carbon::parse($value)->format('d/m/Y');
                    }
                } catch (Exception $e) {}
            }

            $templateProcessor->setValue($key, (string)$value);
        }

        // SIMPAN FILE WORD (FOTO & QR CODE SUDAH AMAN & KEKUNCI DI DALAM FILE .DOCX INI)
        $templateProcessor->saveAs($pathFile);

        // Bersihkan file QR sementara dari folder temp server
        if ($tempQrPath && file_exists($tempQrPath)) {
            @unlink($tempQrPath);
        }

        return 'documents/' . $namaFileFisik;
    }

    public function generatePdf(DokumenCetak $antrean, int $copies = 1, string $pages = 'All', int $batasFoto = 0, int $kolomPerBaris = 4): string
    {
        // 1. SELALU GENERATE ULANG DOCX AGAR DATA HASIL EDIT SELALU UPDATE
        $docxRelativePath  = $this->generate($antrean, $batasFoto, $kolomPerBaris);
        $antrean->file_path = $docxRelativePath;
        $antrean->save();
        $docxAbsPath       = public_path($docxRelativePath);

        // 2. KONVERSI FILE WORD YANG SUDAH JADI LANGSUNG KE PDF
        $pdfDirPath  = public_path('pdfs');

        if (!is_dir($pdfDirPath)) {
            @mkdir($pdfDirPath, 0777, true);
        }

        $pdfFileName     = pathinfo($docxAbsPath, PATHINFO_FILENAME) . '.pdf';
        $pdfAbsPath      = $pdfDirPath . '/' . $pdfFileName;
        $pdfRelativePath = 'pdfs/' . $pdfFileName;

        if (file_exists($pdfAbsPath)) {
            @unlink($pdfAbsPath);
        }

        $sofficePath = '';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $portablePath1 = base_path('libreoffice/program/soffice.exe');
            $portablePath2 = base_path('libreoffice/App/libreoffice/program/soffice.exe');
            $installedPath = 'C:\\Program Files\\LibreOffice\\program\\soffice.exe';

            if (file_exists($portablePath1)) $sofficePath = $portablePath1;
            elseif (file_exists($portablePath2)) $sofficePath = $portablePath2;
            elseif (file_exists($installedPath)) $sofficePath = $installedPath;
            else throw new Exception('Mesin LibreOffice tidak ditemukan.');
        } else {
            $linuxPath = '/usr/bin/libreoffice';
            $sofficePath = file_exists($linuxPath) ? $linuxPath : 'soffice';
        }

        $envProfile = '-env:UserInstallation=' . sprintf('file:///%s/lo_profile', str_replace('\\', '/', sys_get_temp_dir()));
        $flags      = "--headless --norestore --invisible --nodefault --nofirststartwizard {$envProfile}";

        $cmd = ($sofficePath === 'soffice')
            ? "soffice {$flags} --convert-to pdf --outdir " . escapeshellarg($pdfDirPath) . " " . escapeshellarg($docxAbsPath)
            : '"' . $sofficePath . '" ' . $flags . ' --convert-to pdf --outdir ' . escapeshellarg($pdfDirPath) . ' ' . escapeshellarg($docxAbsPath);

        exec($cmd);

        if (!file_exists($pdfAbsPath)) {
            throw new Exception('Gagal konversi PDF via LibreOffice.');
        }

        return $pdfRelativePath;
    }

    private function filterValidPhotos($rawPhotos, int $batasFoto): array
    {
        if (!is_array($rawPhotos)) {
            return [];
        }

        $validPhotos = [];
        foreach ($rawPhotos as $p) {
            $path = '';
            $ket  = '';

            if (is_array($p)) {
                $path = $p['path'] ?? $p['path_foto'] ?? $p['url'] ?? '';
                $ket  = $p['keterangan'] ?? $p['caption'] ?? $p['barang'] ?? '';
            } elseif (is_string($p)) {
                $path = $p;
            }

            if (!empty($path) && file_exists(public_path($path))) {
                // Langsung ambil path foto tanpa perlu diproses lewat GD / imagecreate
                $validPhotos[] = [
                    'path'       => $path,
                    'keterangan' => $ket,
                ];
            }
        }

        if ($batasFoto > 0 && count($validPhotos) > $batasFoto) {
            $validPhotos = array_slice($validPhotos, 0, $batasFoto);
        }

        return $validPhotos;
    }

    private function injectTorBarangVariables(TemplateProcessor $templateProcessor, array $variables): void
    {
        $templateProcessor->setValue('nama_peninjau', $variables['nama_peninjau'] ?? $variables['peninjau'] ?? $variables['nama_penyerah'] ?? '');
        $templateProcessor->setValue('bidang1', $variables['bidang1'] ?? $variables['bidang_penyerah'] ?? '');
        $templateProcessor->setValue('nama_penyetuju', $variables['nama_penyetuju'] ?? $variables['penyetuju'] ?? $variables['nama_penerima'] ?? '');
        $templateProcessor->setValue('bidang2', $variables['bidang2'] ?? $variables['bidang_penerima'] ?? '');
    }

    // FIX: Tambahkan parameter array $rincianBarang
    private function injectLampiranFotoBarang(TemplateProcessor $templateProcessor, array $validPhotos, array $rincianBarang): void
    {
        $photoCount = count($validPhotos);

        if ($photoCount > 0) {
            $kolomPerBaris = 2;
            $rowsNeeded    = (int)ceil($photoCount / $kolomPerBaris);

            try {
                $templateProcessor->cloneRow('foto_1', $rowsNeeded);

                for ($row = 1; $row <= $rowsNeeded; $row++) {
                    $idxKiri  = ($row - 1) * $kolomPerBaris;
                    $idxKanan = $idxKiri + 1;

                    // FOTO & NAMA BARANG KIRI (${foto_1} & ${barang_1})
                    if (isset($validPhotos[$idxKiri])) {
                        $pKiri = $validPhotos[$idxKiri];
                        $templateProcessor->setImageValue("foto_1#{$row}", [
                            'path'   => public_path($pKiri['path']),
                            'width'  => 450,
                            'height' => 250,
                            'ratio'  => true
                        ]);

                        // FIX: Ambil dari rincianBarang, kalau kosong baru ngambil keterangan gambar
                        $namaBrgKiri = $rincianBarang[$idxKiri]['barang'] ?? $rincianBarang[$idxKiri]['nama_barang'] ?? $rincianBarang[$idxKiri]['nama_item'] ?? $pKiri['keterangan'] ?? '';
                        $templateProcessor->setValue("barang_1#{$row}", $namaBrgKiri);
                    } else {
                        $templateProcessor->setValue("foto_1#{$row}", '');
                        $templateProcessor->setValue("barang_1#{$row}", '');
                    }

                    // FOTO & NAMA BARANG KANAN (${foto_2} & ${barang_2})
                    if (isset($validPhotos[$idxKanan])) {
                        $pKanan = $validPhotos[$idxKanan];
                        $templateProcessor->setImageValue("foto_2#{$row}", [
                            'path'   => public_path($pKanan['path']),
                            'width'  => 450,
                            'height' => 250,
                            'ratio'  => true
                        ]);

                        // FIX: Sama halnya untuk bagian kanan
                        $namaBrgKanan = $rincianBarang[$idxKanan]['barang'] ?? $rincianBarang[$idxKanan]['nama_barang'] ?? $rincianBarang[$idxKanan]['nama_item'] ?? $pKanan['keterangan'] ?? '';
                        $templateProcessor->setValue("barang_2#{$row}", $namaBrgKanan);
                    } else {
                        $templateProcessor->setValue("foto_2#{$row}", '');
                        $templateProcessor->setValue("barang_2#{$row}", '');
                    }
                }
            } catch (Throwable $e) {
                for ($i = 1; $i <= 10; $i++) {
                    $idx = $i - 1;
                    if (isset($validPhotos[$idx])) {
                        try {
                            $templateProcessor->setImageValue("foto_{$i}", [
                                'path'   => public_path($validPhotos[$idx]['path']),
                                'width'  => 450,
                                'height' => 250,
                                'ratio'  => true
                            ]);
                            $namaBrgFallback = $rincianBarang[$idx]['barang'] ?? $rincianBarang[$idx]['nama_barang'] ?? $rincianBarang[$idx]['nama_item'] ?? $validPhotos[$idx]['keterangan'] ?? '';
                            $templateProcessor->setValue("barang_{$i}", $namaBrgFallback);
                        } catch (Throwable $ex) {
                            $templateProcessor->setValue("foto_{$i}", '');
                            $templateProcessor->setValue("barang_{$i}", '');
                        }
                    } else {
                        $templateProcessor->setValue("foto_{$i}", '');
                        $templateProcessor->setValue("barang_{$i}", '');
                    }
                }
            }
        } else {
            for ($i = 1; $i <= 10; $i++) {
                $templateProcessor->setValue("foto_{$i}", '');
                $templateProcessor->setValue("barang_{$i}", '');
            }
            $templateProcessor->setValue('foto_kiri', '');
            $templateProcessor->setValue('foto_kanan', '');
            $templateProcessor->setValue('barang_kiri', '');
            $templateProcessor->setValue('barang_kanan', '');
        }
    }
}