<?php

namespace App\Http\Controllers;

use App\Models\Dokumen;
use App\Models\DokumenItem;
use App\Models\DokumenFoto;
use App\Models\Template; 
use PhpOffice\PhpWord\TemplateProcessor; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon; // 🎯 Import Carbon untuk filter tanggal

class DokumenController extends Controller
{
    /**
     * Mengambil daftar dokumen KHUSUS 7 HARI TERAKHIR
     * (Filter berdasarkan jenis_dokumen_id jika ada)
     */
    public function index(Request $request)
    {
        try {
            // 🎯 Hitung tanggal 7 hari yang lalu dari hari ini (Format: YYYY-MM-DD)
            $tujuhHariLalu = Carbon::now()->subDays(7)->format('Y-m-d');

            // 🎯 Query hanya mengambil data tanggal_dokumen >= 7 hari lalu
            $query = Dokumen::with(['user', 'items'])
                ->where('tanggal_dokumen', '>=', $tujuhHariLalu);

            if ($request->has('jenis_dokumen_id') && !empty($request->jenis_dokumen_id)) {
                $query->where('jenis_dokumen_id', $request->jenis_dokumen_id);
            }

            if ($request->has('jenis') && !empty($request->jenis)) {
                $query->where('jenis', $request->jenis);
            }

            $dokumen = $query->latest()->get();

            return response()->json([
                'success' => true,
                'data'    => $dokumen
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data dokumen: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menyimpan data dokumen baru dan generate file word otomatis
     */
    public function store(Request $request)
    {
        return DB::transaction(function () use ($request) {
            
            $pathDatabase = null; 
            $isianData = [];
            $templateNama = '';

            if ($request->template_id) {
                $template = Template::find($request->template_id);
                
                if ($template) {
                    $templateNama = strtolower($template->nama); // Simpan nama template untuk deteksi otomatis
                    $templatePath = public_path($template->file_path);
                    
                    if (file_exists($templatePath)) {
                        $templateProcessor = new TemplateProcessor($templatePath);
                        
                        $isianData = $request->input('isian', $request->all());
                        if (is_string($isianData)) {
                            $isianData = json_decode($isianData, true) ?? [];
                        }
                        
                        $tanggalInput = $request->tanggal_dokumen ?? $isianData['tanggal_input'] ?? date('Y-m-d'); 
                        $tahunOtomatis = date('Y', strtotime($tanggalInput));
                        $bulanAngka = date('n', strtotime($tanggalInput)); 
                        $daftarRomawi = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
                        $bulanRomawiOtomatis = $daftarRomawi[$bulanAngka] ?? 'I';
                        
                        $isianData['no'] = $request->no_dokumen;
                        $isianData['bulan'] = $bulanRomawiOtomatis;
                        $isianData['tahun'] = $tahunOtomatis;
                        
                        foreach ($isianData as $key => $value) {
                            if (!is_array($value)) {
                                $templateProcessor->setValue($key, $value);
                            }
                        }
                        
                        $outputFolder = public_path('templates/outputs');
                        if (!file_exists($outputFolder)) {
                            mkdir($outputFolder, 0777, true);
                        }
                        
                        // Jenis file fisik mengikuti jenis otomatis
                        $jenisFile = $request->jenis ?? (str_contains($templateNama, 'penyerahan') ? 'BA_Penyerahan' : 'SURAT');
                        $namaFileFisik = "Cetak_" . time() . "_" . uniqid() . "_" . $jenisFile . ".docx";
                        $savePath = $outputFolder . '/' . $namaFileFisik;
                        
                        $templateProcessor->saveAs($savePath);
                        $pathDatabase = "templates/outputs/" . $namaFileFisik;
                    }
                }
            }

            // ==========================================
            // LOGIC OTOMATISASI DATA (ANTI MANUAL)
            // ==========================================

            // 1. Otorisasi User Dinamis
            $userIdFinal = $request->user_id ?? (auth()->check() ? auth()->id() : null);

            // 2. Deteksi Jenis Dokumen dari Nama Template jika Request Kosong
            $jenisFinal = $request->jenis;
            if (empty($jenisFinal) && !empty($templateNama)) {
                if (str_contains($templateNama, 'penyerahan')) {
                    $jenisFinal = 'penyerahan';
                } elseif (str_contains($templateNama, 'tugas')) {
                    $jenisFinal = 'surat_tugas';
                } else {
                    $jenisFinal = str_replace(' ', '_', $templateNama); // slug otomatis dari nama template
                }
            }
            $jenisFinal = $jenisFinal ?? 'umum';


            // Ambil data items dari request, bongkar jika terkirim sebagai JSON String akibat FormData
            $itemsData = $request->items ?? $isianData['items'] ?? null;
            if (is_string($itemsData)) {
                $itemsData = json_decode($itemsData, true);
            }

            // Simpan Header ke tabel 'dokumen'
            $dokumen = Dokumen::create([
                'template_id'       => $request->template_id,
                'user_id'           => $userIdFinal, 
                'jenis_dokumen_id'  => $request->jenis_dokumen_id,
                'nomor_dokumen'     => $request->no_dokumen,
                'jenis'             => $jenisFinal,                              
                'tanggal_dokumen'   => $request->tanggal_dokumen ?? date('Y-m-d'),
                'penyerah_id'       => $request->penyerah_id,
                'penerima_id'       => $request->penerima_id,
                'opsi_foto'         => $request->opsi_foto ?? ($request->hasFile('fotos') ? 1 : 0), 
                'kolom_tambahan'    => !empty($isianData) ? json_encode($isianData) : null, 
                'file_sblm_print'   => $pathDatabase,                                                   
                'status_aktif'      => 1
            ]);

            // Simpan detail item ke tabel 'dokumen_item'
            if (is_array($itemsData) && count($itemsData) > 0) {
                foreach ($itemsData as $item) {
                    DokumenItem::create([
                        'dokumen_id'  => $dokumen->id, 
                        'nama_item'   => $item['barang'] ?? $item['nama_item'] ?? null,
                        'spesifikasi' => $item['spesifikasi'] ?? null,
                        'jumlah'      => $item['jumlah'] ?? 0,
                        'keterangan'  => $item['keterangan'] ?? $item['keterangan_kerusakan'] ?? null,
                    ]);
                }
            }

            if ($request->hasFile('fotos')) {
                foreach ($request->file('fotos') as $foto) {
                    $path = $foto->store('dokumen_fotos', 'public');
                    DokumenFoto::create([
                        'dokumen_id' => $dokumen->id, 
                        'path_foto'  => $path
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Gass! Dokumen berhasil diarsipkan langsung & file .docx otomatis siap!', 
                'id' => $dokumen->id,
                'file_path' => $pathDatabase
            ], 201);
        });
    }

    /**
     * Mengambil nomor urut dokumen berikutnya buat auto-increment di React
     */
    public function nextCounter()
    {
        try {
            $totalDokumen = Dokumen::count();
            return response()->json([
                'success' => true,
                'next_counter' => $totalDokumen + 1
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data counter: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menghapus dokumen (Dibutuhkan oleh route DELETE /dokumen/{id})
     */
    public function destroy($id)
    {
        try {
            $dokumen = Dokumen::findOrFail($id);

            // Hapus file fisik jika ada
            if ($dokumen->file_sblm_print && file_exists(public_path($dokumen->file_sblm_print))) {
                @unlink(public_path($dokumen->file_sblm_print));
            }

            // Hapus relasi items dan foto
            DokumenItem::where('dokumen_id', $id)->delete();
            DokumenFoto::where('dokumen_id', $id)->delete();
            
            $dokumen->delete();

            return response()->json([
                'success' => true,
                'message' => 'Dokumen berhasil dihapus permanen.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus dokumen: ' . $e->getMessage()
            ], 500);
        }
    }
}