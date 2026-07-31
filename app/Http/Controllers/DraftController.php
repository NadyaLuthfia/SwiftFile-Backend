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
                $q->where('user_id', $user->id);
                
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
     * 🔍 Mengambil satu data draft spesifik
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
        ]);

        $isianVariabel = $request->except(['template_id', '_token']);

        if ($request->hasFile('rincian_barang')) {
            foreach ($request->file('rincian_barang') as $index => $item) {
                if (isset($item['foto'])) {
                    $path = $item['foto']->store('draft_images', 'public');
                    $isianVariabel['rincian_barang'][$index]['foto'] = $path;
                }
            }
        }

        $draft = Draft::create([
            'user_id'      => $request->user()->id, 
            'template_id'  => $request->template_id,
            'nama_draft'   => 'PENDING', 
            'no_dokumen'   => 'PENDING_NUMBER', 
            'data_input'   => json_encode($isianVariabel)
        ]);

        $this->sinkronkanNomorOtomatis($draft);

        return response()->json([ 'success' => true, 'message' => 'Draft berhasil dibuat!', 'data' => $draft ], 201);
    }

    /**
     * 📝 Mengubah data draft
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $draft = Draft::findOrFail($id);

        $identity = $user->name ?? $user->username ?? $user->nama ?? null;
        $dataInputOld = is_array($draft->data_input) ? $draft->data_input : json_decode($draft->data_input, true);
        $namaPenanggung = $dataInputOld['nama_penanggung'] ?? '';

        $hasAccess = ($draft->user_id == $user->id) || 
                    (!empty($identity) && strtolower($namaPenanggung) === strtolower($identity));

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah draft ini.'
            ], 403);
        }

        $isianVariabel = $request->except(['template_id', '_token', '_method']);

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

            if ($user && strtolower($user->role) === 'user' && $draft->user_id !== $user->id) {
                return response()->json([ 'success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menghapus draft ini.' ], 403);
            }

            $isianData = is_string($draft->data_input) ? json_decode($draft->data_input, true) : $draft->data_input;

            if (is_array($isianData)) {
                foreach ($isianData as $value) {
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