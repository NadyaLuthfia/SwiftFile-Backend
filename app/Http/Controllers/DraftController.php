<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Draft; 
use App\Models\Template;
use App\Services\DocumentNumberService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB; 

class DraftController extends Controller
{
    protected $numberService;

    public function __construct(DocumentNumberService $numberService)
    {
        $this->numberService = $numberService;
    }

    /**
     * 🧠 HELPER PRIVATE FUNCTION (Shared Counter & Format Gabungan Otomatis via Service Terpusat)
     */
    private function sinkronkanNomorOtomatis($draft)
    {
        $namaTemplate = $draft->template ? ($draft->template->nama ?? $draft->template->nama_template) : 'Dokumen';
        
        // 1. Ambil data_input yang tersimpan di DB
        $isianData = is_string($draft->data_input) 
            ? json_decode($draft->data_input, true) 
            : (is_array($draft->data_input) ? $draft->data_input : []);
        
        if (!is_array($isianData)) {
            $isianData = [];
        }

        // 🧼 Clean string 'null'
        foreach ($isianData as $key => $value) {
            if ($value === null || $value === 'null' || (is_string($value) && strtolower($value) === 'null')) {
                $isianData[$key] = '';
            }
        }

        // 🎯 2. Ambil pengidentifikasi (Utamakan nama_barang yang diisi user)
        $pengidentifikasi = '';
        
        if (!empty($isianData['nama_barang'])) {
            $pengidentifikasi = $isianData['nama_barang'];
        } elseif (!empty($isianData['nama_alat'])) {
            $pengidentifikasi = $isianData['nama_alat'];
        } elseif (!empty($isianData['item'])) {
            $tambahanSpesifikasi = !empty($isianData['spesifikasi']) ? " (" . $isianData['spesifikasi'] . ")" : "";
            $pengidentifikasi = $isianData['item'] . $tambahanSpesifikasi;
        } elseif (!empty($isianData['nama_penyerah'])) {
            $pengidentifikasi = "Dari " . $isianData['nama_penyerah'];
        } elseif (!empty($isianData['nama_pekerja'])) {
            $lokasi = !empty($isianData['lokasi']) ? " (" . $isianData['lokasi'] . ")" : "";
            $pengidentifikasi = trim("{$isianData['nama_pekerja']}{$lokasi}");
        } else {
            $pengidentifikasi = 'Draft Baru';
        }

        $nomorGabunganDraft = "{$namaTemplate} - {$pengidentifikasi}";

        // 🎯 3. IF-ELSE KHUSUS DOKUMEN TOR
        // Cek apakah nama template mengandung kata "TOR"
        $isTor = str_contains(strtolower($namaTemplate), 'tor');

        if ($isTor) {
            // Jika TOR, nomor dokumen diset "Tidak Ada"
            $nomorDokumenLengkap = 'Tidak Ada';
            $isianData['no'] = '-';
            $isianData['bulan'] = '';
            $isianData['tahun'] = date('Y');
            
            $bulanRomawi = '';
            $noOtomatis = '-';
        } else {
            // Jika BUKAN TOR, jalankan penomoran otomatis seperti biasa
            $tanggalInput = $isianData['tanggal_input'] ?? ($draft->created_at ? $draft->created_at->format('Y-m-d') : date('Y-m-d')); 
            $numData = $this->numberService->generate($draft->template_id, $tanggalInput);

            $nomorDokumenLengkap = $numData['nomor_surat_lengkap'];
            $isianData['no'] = $numData['no'];
            $isianData['bulan'] = $numData['bulan_teks']; 
            $isianData['tahun'] = $numData['tahun'];
            
            $bulanRomawi = $numData['bulan_romawi'];
            $noOtomatis = $numData['no'];
        }

        // 🔏 4. Simpan Perubahan ke Model Draft
        $draft->nama_draft = $nomorGabunganDraft;
        $draft->no_dokumen = $nomorDokumenLengkap; 
        
        $draft->data_input = is_array($draft->data_input) ? $isianData : json_encode($isianData);
        $draft->save();

        return [
            'no_otomatis'          => $noOtomatis,
            'nomor_surat_lengkap'  => $nomorDokumenLengkap,
            'bulan_romawi'         => $bulanRomawi,
            'tahun'                => $isianData['tahun'],
            'isian_data'           => $isianData
        ];
    }

    /**
     * 📋 Menampilkan list draft
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Draft::with(['template', 'user'])->orderBy('updated_at', 'desc');

        if ($user) {
            $query->where(function($q) use ($user) {
                // Tambahin awalan 'drafts.' biar database gak bingung
                $q->where('drafts.user_id', $user->id)
                  ->orWhere('drafts.assigned_to_id', $user->id); 
                
                $identity = $user->name ?? $user->username ?? $user->nama ?? null;
                if (!empty($identity)) {
                    $q->orWhereRaw("LOWER(drafts.data_input) LIKE ?", ['%"nama_penanggung":"' . strtolower($identity) . '"%']);
                }
            });
        }

        $drafts = $query->get();
        
        // 🚨 KACAMATA X-RAY: Biar kita tau Laravel ngebaca ID kamu berapa!
        return response()->json([ 
            'status' => 'success', 
            'ID_YANG_LAGI_LOGIN' => $user ? $user->id : 'TIDAK TERDETEKSI', 
            'data' => $drafts 
        ], 200);
    }

    /**
     * 🔍 Mengambil satu data draft spesifik (Untuk Form Edit React)
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            $query = Draft::with(['template', 'user']);

            if ($user && strtolower($user->role) === 'user') {
                $query->where(function($q) use ($user) {
                    // 👈 FIX: Beri akses "lihat" ke form edit untuk penanggung jawab
                    $q->where('user_id', $user->id)
                      ->orWhere('assigned_to_id', $user->id);
                    
                    $identity = $user->name ?? $user->username ?? $user->nama ?? null;
                    if (!empty($identity)) {
                        $q->orWhereRaw("LOWER(data_input) LIKE ?", ['%"nama_penanggung":"' . strtolower($identity) . '"%']);
                    }
                });
            }

            $draft = $query->findOrFail($id);

            // 🔓 UNBOXING DATA INPUT: Ratakan variables & isian ke root level
            $dataInput = is_string($draft->data_input) ? json_decode($draft->data_input, true) : (array)$draft->data_input;

            if (isset($dataInput['variables'])) {
                $vars = is_string($dataInput['variables']) ? json_decode($dataInput['variables'], true) : (array)$dataInput['variables'];
                if (is_array($vars)) {
                    $dataInput = array_merge($vars, $dataInput);
                }
            }

            if (isset($dataInput['isian'])) {
                $isian = is_string($dataInput['isian']) ? json_decode($dataInput['isian'], true) : (array)$dataInput['isian'];
                if (is_array($isian)) {
                    $dataInput = array_merge($isian, $dataInput);
                }
            }

            // 🖼️ FORMAT URL FOTO DRAFT UNTUK PREVIEW REACT
            if (isset($dataInput['foto']) && is_array($dataInput['foto'])) {
                $formattedPhotos = [];
                foreach ($dataInput['foto'] as $fotoItem) {
                    if (is_array($fotoItem) && isset($fotoItem['path'])) {
                        $fotoItem['full_url'] = asset($fotoItem['path']);
                        $formattedPhotos[] = $fotoItem;
                    } elseif (is_string($fotoItem) && !empty($fotoItem)) {
                        $formattedPhotos[] = [
                            'path'       => $fotoItem,
                            'full_url'   => asset($fotoItem),
                            'keterangan' => 'Foto Lampiran'
                        ];
                    }
                }
                $dataInput['foto'] = $formattedPhotos;
            }

            $draft->data_input = $dataInput;

            return response()->json([ 'status' => 'success', 'data' => $draft ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Draft tidak ditemukan, atau Anda tidak memiliki akses.'
            ], 404);
        }
    }

    /**
     * 📥 TOMBOL "SIMPAN DRAFT" (BUAT BARU)
     */
    public function store(Request $request)
    {
        $request->validate([
            'template_id' => 'required',
        ]);

        $isianVariabel = $request->except(['template_id', '_token']);

        // 🔓 Ratakan jika terbungkus
        if (isset($isianVariabel['variables'])) {
            $vars = is_string($isianVariabel['variables']) ? json_decode($isianVariabel['variables'], true) : (array)$isianVariabel['variables'];
            unset($isianVariabel['variables']);
            if (is_array($vars)) {
                $isianVariabel = array_merge($vars, $isianVariabel);
            }
        }

        if (isset($isianVariabel['isian'])) {
            $isian = is_string($isianVariabel['isian']) ? json_decode($isianVariabel['isian'], true) : (array)$isianVariabel['isian'];
            unset($isianVariabel['isian']);
            if (is_array($isian)) {
                $isianVariabel = array_merge($isian, $isianVariabel);
            }
        }

        // 📷 Handle Upload Foto Lampiran / Foto Barang ke uploads/lampiran
        $photoField = $request->hasFile('foto') ? 'foto' : ($request->hasFile('foto_barang') ? 'foto_barang' : null);

        if ($photoField) {
            $dirPath = public_path('uploads/lampiran');
            if (!is_dir($dirPath)) {
                @mkdir($dirPath, 0777, true);
            }

            $uploadedPhotos = [];
            $files = is_array($request->file($photoField)) ? $request->file($photoField) : [$request->file($photoField)];

            foreach ($files as $file) {
                if ($file && $file->isValid()) {
                    $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $file->move($dirPath, $filename);

                    $uploadedPhotos[] = [
                        'path'       => 'uploads/lampiran/' . $filename,
                        'keterangan' => 'Foto Lampiran'
                    ];
                }
            }

            if (!empty($uploadedPhotos)) {
                $isianVariabel['foto'] = $uploadedPhotos;
            }
        }

        // 🕵️ FIX BARU: Cari ID User berdasarkan ID Karyawan dari Frontend
        $realUserId = null;
        if ($request->assigned_to_id) {
            $realUserId = DB::table('users')
                            ->where('karyawan_id', $request->assigned_to_id)
                            ->value('id');
        }

        $draft = Draft::create([
            'user_id'      => $request->user()->id, 
            'template_id'  => $request->template_id,
            'nama_draft'   => 'PENDING', 
            'no_dokumen'   => 'PENDING_NUMBER', 
            'data_input'   => json_encode($isianVariabel),
            'assigned_to_id' => $realUserId // 👈 Menyimpan ID dari tabel users
        ]);

        $this->sinkronkanNomorOtomatis($draft);

        return response()->json([ 'success' => true, 'message' => 'Draft berhasil dibuat!', 'data' => $draft ], 201);
    }

    /**
     * 📝 TOMBOL "SIMPAN DRAFT" (EDIT / UPDATE DRAFT LAMA)
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $draft = Draft::findOrFail($id);

        $identity = $user->name ?? $user->username ?? $user->nama ?? null;
        $dataInputOld = is_array($draft->data_input) ? $draft->data_input : json_decode($draft->data_input, true);
        $namaPenanggung = $dataInputOld['nama_penanggung'] ?? '';

        // 👈 FIX: Tambah bypass validasi agar penanggung jawab bisa update data
        $hasAccess = ($draft->user_id == $user->id) || 
                     ($draft->assigned_to_id == $user->id) || 
                    (!empty($identity) && strtolower($namaPenanggung) === strtolower($identity));

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah draft ini.'
            ], 403);
        }

        $isianVariabel = $request->except(['template_id', '_token', '_method', 'foto_lama']);

        // 🔓 Unboxing data terbungkus
        if (isset($isianVariabel['variables'])) {
            $vars = is_string($isianVariabel['variables']) ? json_decode($isianVariabel['variables'], true) : (array)$isianVariabel['variables'];
            unset($isianVariabel['variables']);
            if (is_array($vars)) $isianVariabel = array_merge($vars, $isianVariabel);
        }

        if (isset($isianVariabel['isian'])) {
            $isian = is_string($isianVariabel['isian']) ? json_decode($isianVariabel['isian'], true) : (array)$isianVariabel['isian'];
            unset($isianVariabel['isian']);
            if (is_array($isian)) $isianVariabel = array_merge($isian, $isianVariabel);
        }

        // 🖼️ 1. AMBIL FOTO LAMA YANG DITERUSKAN DARI FE
        $fotoFinal = [];
        if ($request->has('foto_lama')) {
            $fotoLama = json_decode($request->foto_lama, true);
            if (is_array($fotoLama)) {
                $fotoFinal = $fotoLama;
            }
        } elseif (isset($dataInputOld['foto'])) {
            $fotoFinal = $dataInputOld['foto'];
        }

        // 📷 2. UPLOAD FOTO BARU
        $photoField = $request->hasFile('foto') ? 'foto' : ($request->hasFile('foto_barang') ? 'foto_barang' : null);

        if ($photoField) {
            $dirPath = public_path('uploads/lampiran');
            if (!is_dir($dirPath)) {
                @mkdir($dirPath, 0777, true);
            }

            $files = is_array($request->file($photoField)) ? $request->file($request->photoField) : [$request->file($photoField)];

            foreach ($files as $file) {
                if ($file && $file->isValid()) {
                    $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $file->move($dirPath, $filename);

                    $fotoFinal[] = [
                        'path'       => 'uploads/lampiran/' . $filename,
                        'keterangan' => 'Foto Lampiran'
                    ];
                }
            }
        }

        if (!empty($fotoFinal)) {
            $isianVariabel['foto'] = $fotoFinal;
        }

        // 👈 FIX BARU: Update assigned_to_id dengan nyari ke table 'users' dulu
        if ($request->has('assigned_to_id')) {
            $realUserId = DB::table('users')
                            ->where('karyawan_id', $request->assigned_to_id)
                            ->value('id');
                            
            $draft->assigned_to_id = $realUserId; // 👈 Sistem masukin ID dari table users!
        }

        $draft->data_input = json_encode($isianVariabel);
        $draft->save();

        $this->sinkronkanNomorOtomatis($draft);

        return response()->json([
            'status' => 'success',
            'message' => 'Draft berhasil diperbarui!',
            'data' => $draft
        ], 200);
    }

    /**
     * 🗑️ Menghapus draf & file pendukungnya
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            $draft = Draft::findOrFail($id);

            // Tetap hanya user pembuat yang berhak menghapus draft
            if ($user && strtolower($user->role) === 'user' && $draft->user_id !== $user->id) {
                return response()->json([ 'success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menghapus draft ini.' ], 403);
            }

            $isianData = is_string($draft->data_input) ? json_decode($draft->data_input, true) : $draft->data_input;

            if (is_array($isianData)) {
                // Hapus foto di public/uploads/lampiran
                if (isset($isianData['foto']) && is_array($isianData['foto'])) {
                    foreach ($isianData['foto'] as $fotoItem) {
                        if (isset($fotoItem['path']) && file_exists(public_path($fotoItem['path']))) {
                            // MATIKAN KODE INI BIAR FOTO NETEP DI FOLDER
                            // @unlink(public_path($fotoItem['path'])); 
                        }
                    }
                }

                // Fallback untuk file lama yang masih ada di storage/app/public/draft_images
                foreach ($isianData as $value) {
                    if (is_string($value) && str_contains($value, 'draft_images/') && Storage::disk('public')->exists($value)) {
                        // MATIKAN JUGA STORAGE DELETE INI
                        // Storage::disk('public')->delete($value);
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