<?php

namespace App\Services;

use App\Models\Template;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DocumentNumberService
{
    public static function getBulanRomawi(int $bulanAngka): string
    {
        $romawiArr = [
            1  => 'I',   2 => 'II',  3 => 'III', 4 => 'IV', 
            5  => 'V',   6 => 'VI',  7 => 'VII', 8 => 'VIII', 
            9  => 'IX', 10 => 'X',  11 => 'XI', 12 => 'XII'
        ];

        return $romawiArr[$bulanAngka] ?? 'I';
    }

    public static function getBulanIndo(int $bulanAngka): string
    {
        $daftarBulanIndo = [
            1 => 'Januari',   2 => 'Februari', 3 => 'Maret',    4 => 'April', 
            5 => 'Mei',       6 => 'Juni',     7 => 'Juli',     8 => 'Agustus', 
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        return $daftarBulanIndo[$bulanAngka] ?? 'Januari';
    }

    /**
     * LOGIKA UTAMA GENERATE NOMOR & DOKUMEN
     */
    public function generate(int $templateId, ?string $tanggalInput = null): array
    {
        // A. Ambil Data Template
        $template = Template::find($templateId);
        $namaTemplate = $template ? ($template->nama ?? $template->nama_template) : 'Dokumen';
        $namaTemplateLower = strtolower($namaTemplate);
        
        // B. Deteksi Jenis Template
        $isSuratTugas = str_contains($namaTemplateLower, 'surat tugas');
        $isTor        = str_contains($namaTemplateLower, 'tor');

        // C. Olah Tanggal & Bulan
        $dateObj = $tanggalInput ? Carbon::parse($tanggalInput) : Carbon::now();
        $tahun = $dateObj->format('Y');
        $bulanAngka = (int)$dateObj->format('n');
        
        $bulanRomawi = self::getBulanRomawi($bulanAngka);
        $bulanTeksIndo = self::getBulanIndo($bulanAngka);

        // D. Ambil ID Template untuk Pengelompokan Query
        $suratTugasIds = Template::where('nama', 'like', '%surat tugas%')->pluck('id');
        $torIds        = Template::where('nama', 'like', '%tor%')->pluck('id');

        // E. Percabangan Jalur Nomor Urut
        if ($isSuratTugas) {
            // 1. Jalur Khusus Surat Tugas
            $kodeFormat = 'SPPD/O';
            
            // 🔥 UBAHAN KHUSUS SURAT TUGAS: Gabungin jumlah yang ngantri cetak + yang udah di arsip
            $jmlCetak = DB::table('dokumen_cetak')
                            ->whereYear('created_at', $tahun)
                            ->whereIn('template_id', $suratTugasIds)
                            ->count();
                            
            $jmlArsip = DB::table('dokumen')
                            ->whereYear('created_at', $tahun)
                            ->whereIn('template_id', $suratTugasIds)
                            ->count();
            
            $totalCetak = $jmlCetak + $jmlArsip;
            $noUrut     = str_pad($totalCetak + 1, 3, '0', STR_PAD_LEFT);

            $nomorSuratLengkap = "{$noUrut}/BTG-{$bulanRomawi}/{$kodeFormat}/{$tahun}";
            $namaDokumenFinal  = "{$namaTemplate} - {$nomorSuratLengkap}";

        } elseif ($isTor) {
            // 2. Jalur Khusus TOR (Tanpa Nomor Dokumen & Tidak Mengganggu BA)
            $kodeFormat        = '';
            $noUrut            = '';
            $nomorSuratLengkap = '';
            $namaDokumenFinal  = $namaTemplate;

        } else {
            // 3. Jalur Khusus BA (Kecualikan Surat Tugas DAN TOR)
            $kodeFormat = 'BA';
            
            // 🔥 UBAHAN KHUSUS BA: Gabungin jumlah yang ngantri cetak + yang udah di arsip
            $jmlCetak = DB::table('dokumen_cetak')
                            ->whereYear('created_at', $tahun)
                            ->whereNotIn('template_id', $suratTugasIds->merge($torIds))
                            ->count();
                            
            $jmlArsip = DB::table('dokumen')
                            ->whereYear('created_at', $tahun)
                            ->whereNotIn('template_id', $suratTugasIds->merge($torIds))
                            ->count();
            
            $totalCetak = $jmlCetak + $jmlArsip;
            $noUrut     = str_pad($totalCetak + 1, 3, '0', STR_PAD_LEFT);

            $nomorSuratLengkap = "{$noUrut}/BTG-{$bulanRomawi}/{$kodeFormat}/{$tahun}";
            $namaDokumenFinal  = "{$namaTemplate} - {$nomorSuratLengkap}";
        }

        return [
            'no'                   => $noUrut,
            'no_urut'              => $noUrut,
            'bulan_romawi'         => $bulanRomawi,
            'bulan_teks'           => $bulanTeksIndo,
            'tahun'                => $tahun,
            'kode_format'          => $kodeFormat,
            'nomor_surat_lengkap'  => $nomorSuratLengkap,
            'nama_dokumen_final'   => $namaDokumenFinal,
            'nama_template'        => $namaTemplate,
            'is_surat_tugas'       => $isSuratTugas,
            'is_tor'               => $isTor,
        ];
    }
}