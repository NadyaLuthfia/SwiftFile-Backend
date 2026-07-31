<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DokumenCetak;
use Throwable;

class MonitoringController extends Controller
{
    public function monitoringAll(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user || !in_array($user->role, ['master_admin', 'admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Oops! Kamu tidak memiliki hak akses untuk halaman monitoring ini.'
                ], 403);
            }

            // 💡 Load relasi 'user' sekaligus nested relasi 'karyawan'
            $query = DokumenCetak::with('user.karyawan')
                        ->whereNotNull('foto_ttd')
                        ->where('foto_ttd', '!=', '')
                        ->latest()
                        ->take(30);

            $data = $query->get()->map(function($dokumen) {
                // 🎯 Ambil nama_lengkap dari tabel karyawan. 
                // Jika tidak ada/null, otomatis panggil username atau fallback ke 'Operator Admin'
                $dokumen->nama_pembuat = $dokumen->user->karyawan->nama_lengkap 
                                         ?? $dokumen->user->username 
                                         ?? 'Operator Admin';

                return $dokumen;
            });

            return response()->json([
                'status' => 'success',
                'data' => $data
            ], 200);

        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}