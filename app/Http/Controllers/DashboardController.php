<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Template;
use App\Models\Draft;
use App\Models\DokumenCetak;
use Illuminate\Support\Facades\DB; 

class DashboardController extends Controller
{
    /**
     * Mengambil seluruh hitungan statistik real-time untuk Dashboard (Berlaku untuk SEMUA Role)
     */
    public function dashboardStats(Request $request) 
    {
        try {
            $user = $request->user(); // 🔐 Ambil data user yang sedang login

            // Ambil identitas user dengan sistem pengaman berlapis
            $identity = $user->name ?? $user->username ?? $user->nama ?? null;

            // 1. Hitung total data dari master template (Global, semua role bisa lihat)
            $totalTemplates = Template::count();

            // 2. ✨ HITUNG DRAFT BERKAS (🔒 Sekarang dikunci untuk semua role)
            $draftQuery = Draft::query();
            if ($user) {
                $draftQuery->where(function($q) use ($user, $identity) {
                    $q->where('user_id', $user->id);
                    
                    if (!empty($identity)) {
                        // Disamakan dengan DraftController agar hitungannya sinkron sempurna
                        $q->orWhereRaw("LOWER(data_input) LIKE ?", ['%"nama_penanggung":"' . strtolower($identity) . '"%']);
                    }
                });
            }
            $totalDrafts = $draftQuery->count();
            
            // 3. ✨ HITUNG ANTREAN CETAK (🔒 Sekarang dikunci untuk semua role)
            $antreanQuery = DokumenCetak::where(function($query) {
                                $query->whereNull('file_path')
                                      ->orWhere('file_path', '');
                            });
            if ($user) {
                $antreanQuery->where(function($q) use ($user, $identity) {
                    $q->where('user_id', $user->id);
                    
                    if (!empty($identity)) {
                        // Scan nama penanggung jawab di kolom variables untuk semua role
                        $q->orWhereRaw("LOWER(variables) LIKE ?", ['%' . strtolower($identity) . '%']);
                    }
                });
            }
            $totalAntrean = $antreanQuery->count();
            
            // 4. ✨ HITUNG TOTAL ARSIP SAH (🔒 Sekarang dikunci untuk semua role)
            $arsipQuery = DokumenCetak::whereNotNull('file_path')
                                    ->where('file_path', '!=', '');

            if ($user) {
                $arsipQuery->where(function($q) use ($user, $identity) {
                    $q->where('user_id', $user->id);
                    
                    if (!empty($identity)) {
                        // Scan nama penanggung jawab di kolom variables untuk semua role
                        $q->orWhereRaw("LOWER(variables) LIKE ?", ['%' . strtolower($identity) . '%']);
                    }
                });
            }
            $totalArsip = $arsipQuery->count();

            // 5. Kembalikan response JSON terpadu ke React Frontend dengan key yang sama persis
            return response()->json([
                'success' => true,
                'stats' => [ 
                    'total_templates' => $totalTemplates,
                    'total_drafts'    => $totalDrafts,
                    'total_antrean'   => $totalAntrean,
                    'total_arsip'     => $totalArsip,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat statistik dashboard: ' . $e->getMessage()
            ], 500);
        }
    }
}