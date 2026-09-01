<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Dokumen;
use Illuminate\Support\Facades\Storage;
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

            $query = Dokumen::with('user.karyawan')
                        ->latest()
                        ->take(30);

            $data = $query->get()->map(function($dokumen) {
                $dokumen->nama_pembuat = $dokumen->user->karyawan->nama_lengkap 
                                         ?? $dokumen->user->username 
                                         ?? 'Operator Admin';

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

    public function toggleStatus(Request $request, $id)
    {
        try {
            // Tambahan Pengaman: Pastikan hak akses dicek juga dari dalam controller
            $user = $request->user();
            if (!$user || !in_array($user->role, ['master_admin', 'admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Oops! Kamu tidak memiliki hak akses untuk mengubah status.'
                ], 403);
            }

            $dokumen = Dokumen::find($id);
            if (!$dokumen) {
                return response()->json([
                    'status' => 'error', 
                    'message' => 'Dokumen tidak ditemukan'
                ], 404);
            }

            $currentStatus = $dokumen->status_aktif ?? 1;
            
            $dokumen->status_aktif = $currentStatus == 1 ? 0 : 1;
            $dokumen->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Status berhasil diubah!',
                'new_status' => $dokumen->status_aktif
            ], 200);

        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 🌟 FUNGSI BARU UNTUK HAPUS DOKUMEN PERMANEN
    public function destroy(Request $request, $id)
    {
        try {
            // Tambahan Pengaman: Pastikan HANYA master_admin yang bisa menghapus permanen
            $user = $request->user();
            if (!$user || $user->role !== 'master_admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Hanya Master Admin yang diizinkan menghapus dokumen!'
                ], 403);
            }

            $dokumen = Dokumen::find($id);
            if (!$dokumen) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Dokumen tidak ditemukan'
                ], 404);
            }

            // Hapus file dokumen dari storage jika fisik file ada
            if (!empty($dokumen->file_path) && Storage::disk('public')->exists($dokumen->file_path)) {
                Storage::disk('public')->delete($dokumen->file_path);
            }

            // Hapus foto TTD jika ada
            if (!empty($dokumen->foto_ttd) && $dokumen->foto_ttd !== 'tanpa_ttd' && Storage::disk('public')->exists($dokumen->foto_ttd)) {
                Storage::disk('public')->delete($dokumen->foto_ttd);
            }

            // Hapus record di database
            $dokumen->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Dokumen berhasil dihapus secara permanen.'
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal hapus dokumen: ' . $e->getMessage()
            ], 500);
        }
    }
}