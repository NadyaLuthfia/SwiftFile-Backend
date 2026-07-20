<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DokumenCetak;
use App\Models\Template;
use Throwable;

class MonitoringController extends Controller
{
    /**
     * Mengambil seluruh berkas tanpa filter user_id khusus untuk Master Admin & Admin
     */
    public function monitoringAll(Request $request)
    {
        try {
            $user = $request->user();

            // 🔒 PROTEKSI DATA: Pastikan hanya master_admin atau admin yang bisa tembus ke sini
            if (!$user || !in_array($user->role, ['master_admin', 'admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Oops! Kamu tidak memiliki hak akses untuk halaman monitoring ini.'
                ], 403);
            }

            // 🔍 KUNCI ARSIP: Hanya ambil data berkas yang SUDAH upload bukti TTD
            $query = DokumenCetak::with('user')
                        ->whereNotNull('foto_ttd')
                        ->where('foto_ttd', '!=', '')
                        ->latest();

            $data = $query->get()->map(function($dokumen) {
                // 🛠️ 1. Decode JSON string di kolom variables
                $vars = $dokumen->variables;
                if (is_string($vars)) {
                    $vars = json_decode($vars, true);
                } else {
                    $vars = (array)$vars;
                }

                // 🛠️ 2. Ambil data Halaman dan Rangkap dari dalam JSON variables
                $dokumen->halaman = $vars['halaman'] ?? $vars['total_halaman'] ?? '-';
                $dokumen->print_rangkap = $vars['print_rangkap'] ?? $vars['rangkap'] ?? 1;

                // 🛠️ 3. Generate Nama Dokumen Otomatis
                $template = Template::find($dokumen->template_id);
                $namaTemplate = $template ? $template->nama : 'Dokumen';
                
                $noUrut = $vars['no'] ?? '000';
                $tahun = $dokumen->created_at ? $dokumen->created_at->format('Y') : date('Y');
                $bulanAngka = $dokumen->created_at ? (int)$dokumen->created_at->format('n') : (int)date('n');
                
                $romawiArr = [
                    1=>'I', 2=>'II', 3=>'III', 4=>'IV', 5=>'V', 6=>'VI', 
                    7=>'VII', 8=>'VIII', 9=>'IX', 10=>'X', 11=>'XI', 12=>'XII'
                ];
                $bulanRomawi = $romawiArr[$bulanAngka] ?? 'I';

                $dokumen->nama_dokumen = "{$namaTemplate} - {$noUrut}/BTG-{$bulanRomawi}/BA/{$tahun}";

                // 🛠️ 4. PENGAMAT NAMA PEMBUAT (MURNI DARI ACCOUNT USER / USER_ID)
                // Diambil langsung dari username milik relasi user, tanpa menyentuh nama_penanggung lagi!
                $dokumen->nama_pembuat = $dokumen->user->username ?? 'Operator Admin';

                return $dokumen;
            });

            return response()->json([
                'status' => 'success',
                'data' => $data
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}