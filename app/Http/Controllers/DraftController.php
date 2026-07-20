<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Draft; 
use App\Models\Template;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB; 

class DraftController extends Controller
{
    /**
     * 🧠 HELPER PRIVATE FUNCTION (Shared Counter & Format Gabungan Otomatis)
     */
    private function sinkronkanNomorOtomatis($draft)
    {
        $namaTemplate = $draft->template ? $draft->template->nama : 'Dokumen';
        $isianData = is_string($draft->data_input) ? json_decode($draft->data_input, true) : ($draft->data_input ?? []);
        
        // 🧼 1. PEMBERSIH OTOMATIS: Usir semua tulisan 'null' pengganggu biar jadi kosong bersih
        if (is_array($isianData)) {
            foreach ($isianData as $key => $value) {
                if ($value === null || $value === 'null' || strtolower($value) === 'null') {
                    $isianData[$key] = '';
                }
            }
        }

        // 🎯 2. ADAPTIF VISUAL NAMA DRAFT (SINKRON DENGAN REACT)
        $pengidentifikasi = '';
        $isSuratTugas = ($draft->template_id == 3);

        if ($isSuratTugas) {
            // ✨ Khusus Surat Tugas: Ambil nama pekerja dan lokasi
            $pekerja = $isianData['nama_pekerja'] ?? '';
            $lokasi = !empty($isianData['lokasi']) ? " (" . $isianData['lokasi'] . ")" : "";
            $pengidentifikasi = ($pekerja || $lokasi) ? trim("{$pekerja}{$lokasi}") : 'Draft Baru';
        } else {
            // Khusus BA Kerusakan / Penyerahan
            if (!empty($isianData['nama_alat'])) {
                $pengidentifikasi = $isianData['nama_alat'];
            } elseif (!empty($isianData['item'])) {
                $tambahanSpesifikasi = !empty($isianData['spesifikasi']) ? " (" . $isianData['spesifikasi'] . ")" : "";
                $pengidentifikasi = $isianData['item'] . $tambahanSpesifikasi;
            } elseif (!empty($isianData['nama_penyerah'])) {
                $pengidentifikasi = "Dari " . $isianData['nama_penyerah'];
            } else {
                $pengidentifikasi = 'Draft Baru';
            }
        }
        $nomorGabunganDraft = "{$namaTemplate} - {$pengidentifikasi}";

        // 📅 3. LOGIKA TANGGAL & BULAN
        $tanggalInput = $isianData['tanggal_input'] ?? ($draft->created_at ? $draft->created_at->format('Y-m-d') : date('Y-m-d')); 
        $tahunOtomatis = date('Y', strtotime($tanggalInput));
        $bulanAngka = date('n', strtotime($tanggalInput)); 
        
        $daftarRomawi = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
        $bulanRomawiOtomatis = $daftarRomawi[$bulanAngka] ?? 'I';

        $daftarBulanIndo = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $bulanTeksOtomatis = $daftarBulanIndo[$bulanAngka] ?? 'Januari';

        // 🔢 4. UTAMA: LOGIKA COUNTER TERPISAH (STAND-ALONE UNTUK SURAT TUGAS)
        if ($isSuratTugas) {
            // Hitung hanya dokumen cetak yang bertipe Surat Tugas (template_id = 3)
            $totalCetakType = DB::table('dokumen_cetak')
                ->where('template_id', 3)
                ->whereYear('created_at', $tahunOtomatis)
                ->count();
            $kodeDokumen = 'ST';
        } else {
            // Hitung selain Surat Tugas (pooled untuk rumpun BA Berita Acara)
            $totalCetakType = DB::table('dokumen_cetak')
                ->where('template_id', '!=', 3)
                ->whereYear('created_at', $tahunOtomatis)
                ->count();
            $kodeDokumen = 'BA';
        }
            
        $noOtomatis = str_pad($totalCetakType + 1, 3, '0', STR_PAD_LEFT);
        $nomorSuratLengkap = "{$noOtomatis}/BTG-{$bulanRomawiOtomatis}/{$kodeDokumen}/{$tahunOtomatis}";

        // 🔏 5. SINKRONKAN DATA
        $isianData['no'] = $noOtomatis;
        $isianData['bulan'] = $bulanTeksOtomatis; 
        $isianData['tahun'] = $tahunOtomatis;
        
        $draft->nama_draft = $nomorGabunganDraft;
        $draft->no_dokumen = $nomorSuratLengkap; 
        $draft->data_input = $isianData; // ✨ FIX: Langsung array
        $draft->save();

        return [
            'no_otomatis'          => $noOtomatis,
            'nomor_surat_lengkap'  => $nomorSuratLengkap,
            'bulan_romawi'         => $bulanRomawiOtomatis,
            'tahun'                => $tahunOtomatis,
            'isian_data'           => $isianData
        ];
    }

    /**
     * 📋 Menampilkan list draft (Berlaku untuk SEMUA Role)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Draft::with(['template', 'user'])->orderBy('updated_at', 'desc');

        // 🔒 Kunci total untuk siapa pun yang login (User & Master Admin sama-sama dikunci)
        if ($user) {
            $query->where(function($q) use ($user) {
                // 1. Wajib miliknya sendiri berdasarkan ID
                $q->where('user_id', $user->id);
                
                // 2. ATAU namanya disebut spesifik di kolom penanggung jawab
                $identity = $user->name ?? $user->username ?? $user->nama ?? null;
                if (!empty($identity)) {
                    $q->orWhereRaw("LOWER(data_input) LIKE ?", ['%"nama_penanggung":"' . strtolower($identity) . '"%']);
                }
            });
        }

        $drafts = $query->get();
        return response()->json([ 'status' => 'success', 'data' => $drafts ], 200);
    }

    /**
     * 🔍 Mengambil satu data draft spesifik (Versi Aman)
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            $query = Draft::with(['template', 'user']);

            if ($user && strtolower($user->role) === 'user') {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id);
                    
                    $identity = $user->name ?? $user->username ?? $user->nama ?? null;
                    if (!empty($identity)) {
                        $q->orWhereRaw("LOWER(data_input) LIKE ?", ['%"nama_penanggung":"' . strtolower($identity) . '"%']);
                    }
                });
            }

            $draft = $query->findOrFail($id);
            return response()->json([ 'status' => 'success', 'data' => $draft ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Draft tidak ditemukan, atau Anda tidak memiliki akses.'
            ], 404);
        }
    }

    /**
     * 📥 Menyimpan draft baru ke database
     */
    public function store(Request $request)
    {
        $request->validate([
            'template_id' => 'required',
            'nama_draft'  => 'required',
        ]);

        $isianVariabel = $request->input('variables', []);

        if ($request->hasFile('variables')) {
            foreach ($request->file('variables') as $key => $file) {
                $path = $file->store('draft_images', 'public');
                $isianVariabel[$key] = $path;
            }
        }

        $draft = Draft::create([
            'user_id'      => $request->user()->id, 
            'template_id'  => $request->template_id,
            'nama_draft'   => $request->nama_draft,
            'no_dokumen'   => 'PENDING_NUMBER', 
            'data_input'   => $isianVariabel // ✨ FIX: Langsung array
        ]);

        $this->sinkronkanNomorOtomatis($draft);

        return response()->json([ 'success' => true, 'message' => 'Draft berhasil dibuat!' ], 201);
    }

    /**
     * 📝 Mengubah data draft (Versi Fix Sinkron Frontend)
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $draft = Draft::findOrFail($id);

        $identity = $user->name ?? $user->username ?? $user->nama ?? null;
        $dataInputOld = is_array($draft->data_input) ? $draft->data_input : json_decode($draft->data_input, true);
        $namaPenanggung = $dataInputOld['nama_penanggung'] ?? '';

        // 🔒 Gerbang Akses matching Rahma / Sita
        $hasAccess = ($draft->user_id == $user->id) || 
                    (!empty($identity) && strtolower($namaPenanggung) === strtolower($identity));

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah draft ini.'
            ], 403);
        }

        // ⚡ KUNCI PERBAIKAN: Ambil data dari 'variables' seperti fungsi store()
        // Kalau frontend ngirimnya 'variables', kita tampung ke sini. Kalau tidak ada, baru pakai 'data_input'
        $isianVariabel = $request->input('variables') ?? $request->input('data_input') ?? $dataInputOld;

        if ($request->hasFile('variables')) {
            foreach ($request->file('variables') as $key => $file) {
                $path = $file->store('draft_images', 'public');
                $isianVariabel[$key] = $path;
            }
        }

        // Eksekusi update data lama
        $draft->nama_draft = $request->nama_draft ?? $draft->nama_draft;
        $draft->data_input = $isianVariabel;
        $draft->save();

        // Jalankan helper biar nomor otomatis & nama drafnya ikut diperbarui secara real-time
        $this->sinkronkanNomorOtomatis($draft);

        return response()->json([
            'status' => 'success',
            'message' => 'Draft berhasil diperbarui!',
            'data' => $draft
        ], 200);
    }

    /**
     * 🖨️ Mengonversi draft menjadi dokumen .docx (Siap Cetak)
     * Melindungi hak akses penanggung jawab secara berlapis
     */
    public function siapCetak(Request $request)
    {
        try {
            $user = $request->user();
            $templateId = $request->input('template_id');
            $isianData = $request->input('isian', []);

            // 1. 🔒 GERBANG KEAMANAN: Cek hak akses jika dipicu dari data draf yang ada
            $identity = $user->name ?? $user->username ?? $user->nama ?? null;
            $namaPenanggung = $isianData['nama_penanggung'] ?? '';

            if ($request->has('draft_id')) {
                $checkDraft = Draft::find($request->draft_id);
                if ($checkDraft) {
                    // Lolos jika dia pembuat draf ATAU namanya adalah penanggung jawab dokumen
                    $hasAccess = ($checkDraft->user_id == $user->id) || 
                                (!empty($identity) && strtolower($namaPenanggung) === strtolower($identity));
                    
                    if (!$hasAccess) {
                        return response()->json([ 
                            'success' => false, 
                            'message' => 'Anda tidak memiliki hak akses untuk memproses cetak dokumen dari draf ini.' 
                        ], 403);
                    }
                }
            }

            // 2. Validasi keberadaan template master berkas
            $template = Template::findOrFail($templateId);
            $templatePath = storage_path('app/public/' . $template->file_path);

            if (!file_exists($templatePath)) {
                return response()->json([ 'success' => false, 'message' => 'Berkas master template .docx tidak ditemukan di storage.' ], 404);
            }

            // 3. 📅 LOGIKA TANGGAL, BULAN ROMAWI & TAHUN OTOMATIS
            $tanggalInput = $isianData['tanggal_input'] ?? date('Y-m-d');
            $tahunOtomatis = date('Y', strtotime($tanggalInput));
            $bulanAngka = date('n', strtotime($tanggalInput));

            $daftarRomawi = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
            $bulanRomawiOtomatis = $daftarRomawi[$bulanAngka] ?? 'I';
            $daftarBulanIndo = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            $bulanTeksOtomatis = $daftarBulanIndo[$bulanAngka] ?? 'Januari';

            // 4. 🔢 LOGIKA COUNTER NOMOR URUT DOKUMEN CETAK
            $isSuratTugas = ($templateId == 3);
            if ($isSuratTugas) {
                $totalCetakType = DB::table('dokumen_cetak')
                    ->where('template_id', 3)
                    ->whereYear('created_at', $tahunOtomatis)
                    ->count();
                $kodeDokumen = 'ST';
            } else {
                $totalCetakType = DB::table('dokumen_cetak')
                    ->where('template_id', '!=', 3)
                    ->whereYear('created_at', $tahunOtomatis)
                    ->count();
                $kodeDokumen = 'BA';
            }

            $noOtomatis = str_pad($totalCetakType + 1, 3, '0', STR_PAD_LEFT);
            $nomorSuratLengkap = "{$noOtomatis}/BTG-{$bulanRomawiOtomatis}/{$kodeDokumen}/{$tahunOtomatis}";

            // 5. 🧠 PROSES INJEKSI DATA KE TEMPLATE MICROSOFT WORD
            $templateProcessor = new TemplateProcessor($templatePath);

            // Isi tag khusus bulan & tanggal
            $templateProcessor->setValue('bulan', $bulanRomawiOtomatis, 1); 
            $templateProcessor->setValue('bulan', $bulanTeksOtomatis);    

            if (isset($isianData['tanggal'])) {
                $tglAngka = $isianData['tanggal'];
                $tanggalLengkapPenuh = "{$tglAngka} {$bulanTeksOtomatis} {$tahunOtomatis}";

                $templateProcessor->setValue('tanggal', $tglAngka, 1); 
                $templateProcessor->setValue('tanggal', $tanggalLengkapPenuh); 
            }

            // Loop untuk menyuntikkan seluruh variabel dinamis sisa form & gambar pendukung
            foreach ($isianData as $key => $value) {
                if ($key === 'bulan' || $key === 'tanggal') {
                    continue; 
                }
                if (is_string($value) && str_contains($value, 'draft_images/')) {
                    $lokasiGambar = storage_path('app/public/' . $value);
                    if (file_exists($lokasiGambar)) {
                        $templateProcessor->setImageValue($key, [
                            'path' => $lokasiGambar,
                            'width' => 140,
                            'height' => 100,
                            'ratio' => true
                        ]);
                        continue;
                    }
                }
                $templateProcessor->setValue($key, $value);
            }

            // 6. 📂 BUAT FOLDER & SIMPAN FILE SURAT BARU KE STORAGE
            $draftFolder = storage_path('app/public/drafts');
            if (!file_exists($draftFolder)) {
                mkdir($draftFolder, 0777, true);
            }

            $namaFileFisik = time() . '_' . ($request->input('no_dokumen') ?? 'Dokumen') . '.docx';
            $namaFileFisik = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $namaFileFisik);
            $savePath = $draftFolder . '/' . $namaFileFisik;

            $templateProcessor->saveAs($savePath);
            $downloadUrl = url('/drafts/' . $namaFileFisik);

            // 7. 💾 CATAT KE TABEL DOKUMEN CETAK SEBAGAI ARSIP UTUH
            DB::table('dokumen_cetak')->insert([
                'user_id'       => $user->id, 
                'template_id'   => $templateId,
                'nama_dokumen'  => $nomorSuratLengkap, 
                'variables'     => json_encode($isianData), 
                'status'        => 'sudah_dicetak',
                'file_path'     => 'drafts/' . $namaFileFisik, 
                'foto_ttd'      => $isianData['foto_ttd'] ?? null, 
                'created_at'    => now(),
                'updated_at'    => now()
            ]);

            // 8. 🧹 OPSIONAL AUTOMATION: Jika sukses dicetak, hapus draf lamanya agar data tidak menumpuk kotor
            if ($request->has('draft_id')) {
                Draft::where('id', $request->draft_id)->delete();
            }

            return response()->json([ 
                'success' => true, 
                'message' => 'Dokumen resmi berhasil dibuat!',
                'download_url' => $downloadUrl 
            ], 200);

        } catch (\Exception $e) {
            return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
        }
    }

    /**
     * 🗑️ Menghapus draf
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            $draft = Draft::findOrFail($id);

            if ($user && $user->role === 'user' && $draft->user_id !== $user->id) {
                return response()->json([ 'success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menghapus draft ini.' ], 403);
            }

            $isianData = is_string($draft->data_input) ? json_decode($draft->data_input, true) : $draft->data_input;

            if (is_array($isianData)) {
                foreach ($isianData as $key => $value) {
                    if (is_string($value) && str_contains($value, 'draft_images/') && Storage::disk('public')->exists($value)) {
                        Storage::disk('public')->delete($value);
                    }
                }
            }

            $draft->delete();
            return response()->json([ 'success' => true, 'message' => 'Draft sukses dihapus!' ], 200);
        } catch (\Exception $e) {
            return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
        }
    }
}