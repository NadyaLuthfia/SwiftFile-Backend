<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Dokumen; // 👈 PENTING: Ganti ke model Dokumen sayanggg!
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

            // 💡 Load relasi 'user' sekaligus nested relasi 'karyawan' dari tabel Dokumen!
            // Filter foto_ttd dihapus biar datanya keluar FULL semua.
            $query = Dokumen::with('user.karyawan')
                        ->latest()
                        ->take(30);

            $data = $query->get()->map(function($dokumen) {
                // 🎯 Ambil nama_lengkap dari tabel karyawan. 
                $dokumen->nama_pembuat = $dokumen->user->karyawan->nama_lengkap 
                                         ?? $dokumen->user->username 
                                         ?? 'Operator Admin';

                // 💡 TAMBAHAN BARU: Kita buat alias 'nama_dokumen' biar frontend langsung baca!
                $dokumen->nama_dokumen = $dokumen->nomor_dokumen;

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

    public function toggleStatus($id)
    {
        try {
            $dokumen = Dokumen::find($id);
            if (!$dokumen) {
                return response()->json([
                    'status' => 'error', 
                    'message' => 'Dokumen tidak ditemukan'
                ], 404);
            }

            // Cek status saat ini (jika null, anggap 1 sesuai default di database)
            $currentStatus = $dokumen->status_aktif ?? 1;
            
            // Toggle logikanya: Kalau 1 ubah jadi 0, kalau 0 ubah jadi 1
            $dokumen->status_aktif = $currentStatus == 1 ? 0 : 1;
            $dokumen->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Status berhasil diubah!',
                'new_status' => $dokumen->status_aktif
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}