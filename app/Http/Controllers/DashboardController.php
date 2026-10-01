<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Template;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function dashboardStats(Request $request) 
    {
        try {
            $authUser = $request->user();
            $isUserRole = $authUser && ($authUser->role === 'user');

            // 1. Total master template
            $totalTemplates = Template::count();

            // 2. Cek ketersediaan tabel 'dokumen'
            $hasDokumenTable = Schema::hasTable('dokumen');
            
            // 🔒 Total Arsip Sah (Private untuk user) & Total Dokumen Setahun
            $totalArsip = 0;
            $userTotalArsip = 0;
            $totalDokumenTahunIni = 0;

            if ($hasDokumenTable && $authUser) {
                // Hitung total arsip khusus milik user yang sedang login
                $userTotalArsip = DB::table('dokumen')
                    ->where('user_id', $authUser->id)
                    ->count();

                // 🔒 Diselaraskan dengan ArsipController:
                // Angka Arsip & History dihitung khusus berdasarkan dokumen milik user ini
                $totalArsip = $userTotalArsip;

                // 📊 Hitung total dokumen yang dibuat selama 1 tahun berjalan (seluruh sistem)
                $totalDokumenTahunIni = DB::table('dokumen')
                    ->whereYear('created_at', Carbon::now()->year)
                    ->count();
            }

            // Jika tabel dokumen belum ada
            if (!$hasDokumenTable) {
                $allTemplates = Template::all();
                $rekap = $allTemplates->groupBy(function ($template) {
                    return $template->nama_template ?? $template->nama ?? $template->jenis_file ?? 'Dokumen';
                })->map(function ($templatesGroup, $jenisName) {
                    return [
                        'id'               => $templatesGroup->first()->id,
                        'jenis_file'       => $jenisName,
                        'nomor_terakhir'   => 'Belum ada',
                        'tanggal_terakhir' => '-',
                        'pembuat'          => '-',
                        'total_dicetak'    => 0,
                    ];
                })->values();

                return response()->json([
                    'success' => true,
                    'stats'   => [
                        'total_templates'     => $totalTemplates, 
                        'total_arsip'         => $totalArsip,
                        'user_total_arsip'    => $userTotalArsip,
                        'total_dokumen_tahun' => 0
                    ],
                    'rekap'   => $rekap
                ], 200);
            }

            // Tarik seluruh dokumen terkait (Jika user biasa, filter berdasarkan user_id)
            $dokumenQuery = DB::table('dokumen');
            if ($isUserRole && $authUser) {
                $dokumenQuery->where('user_id', $authUser->id);
            }
            $allDokumen = $dokumenQuery->orderBy('id', 'desc')->get();

            $dokumenByTemplate = $allDokumen->groupBy('template_id');

            $userIds = $allDokumen->pluck('user_id')->filter()->unique();
            $karyawanIdsDirect = $allDokumen->pluck('karyawan_id')->filter()->unique();

            $karyawanMapDirect = DB::table('karyawan')
                ->whereIn('id', $karyawanIdsDirect)
                ->pluck('nama_lengkap', 'id');

            $usersMap = User::whereIn('id', $userIds)->get()->keyBy('id');
            
            $userKaryawanIds = $usersMap->pluck('karyawan_id')->filter()->unique();
            $karyawanMapViaUser = DB::table('karyawan')
                ->whereIn('id', $userKaryawanIds)
                ->pluck('nama_lengkap', 'id');

            // Rekapitulasi per template
            $allTemplates = Template::all();
            $groupedTemplates = $allTemplates->groupBy(function ($template) {
                return $template->nama_template ?? $template->nama ?? $template->jenis_file ?? 'Dokumen';
            });

            $rekap = $groupedTemplates->map(function ($templatesGroup, $jenisName) use ($dokumenByTemplate, $karyawanMapDirect, $usersMap, $karyawanMapViaUser) {
                $templateIds = $templatesGroup->pluck('id');

                $matchingDocs = $dokumenByTemplate->filter(function ($doc, $templateId) use ($templateIds) {
                    return $templateIds->contains($templateId);
                })->flatten();

                $totalDicetak = $matchingDocs->count();
                $lastDoc = $matchingDocs->first();

                $nomorTerakhir = 'Belum ada';
                if ($lastDoc) {
                    $rawNomor = $lastDoc->nomor_dokumen ?? $lastDoc->nama_dokumen ?? $lastDoc->nomor ?? $lastDoc->no_dokumen ?? null;
                    if (!empty($rawNomor)) {
                        if (str_contains($rawNomor, ' - ')) {
                            $parts = explode(' - ', $rawNomor, 2);
                            $nomorTerakhir = trim($parts[1]);
                        } else {
                            $nomorTerakhir = trim($rawNomor);
                        }
                    }
                }

                $pembuat = '-';
                if ($lastDoc) {
                    if (!empty($lastDoc->karyawan_id) && isset($karyawanMapDirect[$lastDoc->karyawan_id])) {
                        $pembuat = $karyawanMapDirect[$lastDoc->karyawan_id];
                    }

                    if ($pembuat === '-' && !empty($lastDoc->user_id) && isset($usersMap[$lastDoc->user_id])) {
                        $userDb = $usersMap[$lastDoc->user_id];
                        if (!empty($userDb->karyawan_id) && isset($karyawanMapViaUser[$userDb->karyawan_id])) {
                            $pembuat = $karyawanMapViaUser[$userDb->karyawan_id];
                        } else {
                            $pembuat = $userDb->username ?? $userDb->name ?? 'User #' . $userDb->id;
                        }
                    }
                }

                $tanggalTerakhir = '-';
                if ($lastDoc) {
                    $rawDate = $lastDoc->created_at ?? $lastDoc->tanggal ?? null;
                    if ($rawDate) {
                        $tanggalTerakhir = Carbon::parse($rawDate)->locale('id')->isoFormat('D MMMM YYYY');
                    }
                }

                return [
                    'id'               => $templatesGroup->first()->id,
                    'jenis_file'       => $jenisName,
                    'nomor_terakhir'   => $nomorTerakhir,
                    'tanggal_terakhir' => $tanggalTerakhir,
                    'pembuat'          => $pembuat,
                    'total_dicetak'    => $totalDicetak,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'stats' => [ 
                    'total_templates'     => $totalTemplates,
                    'total_arsip'         => $totalArsip,
                    'user_total_arsip'    => $userTotalArsip,
                    'total_dokumen_tahun' => $totalDokumenTahunIni,
                ],
                'rekap' => $rekap
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat statistik dashboard: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🌟 Endpoint Cadangan Khusus Ringkasan Personal User
     */
    public function myTemplateSummary(Request $request)
    {
        return $this->dashboardStats($request);
    }
}