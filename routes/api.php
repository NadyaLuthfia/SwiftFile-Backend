<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController; 
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\JabatanController;
use App\Http\Controllers\BidangController;
use App\Http\Controllers\KaryawanController;
use App\Http\Controllers\SiapCetakController;
use App\Http\Controllers\ArsipController; 
use App\Http\Controllers\DokumenController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\MonitoringController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    
    Route::get('/user', function (Request $request) {
        return $request->user()->load('karyawan');
    });

    // =========================================================================
    // 🔓 KELOMPOK 1: AKSES UMUM (BISA DIAKSES SEMUA ROLE: USER, ADMIN, MASTER_ADMIN)
    // =========================================================================
    Route::middleware('role:master_admin,admin,user')->group(function () {
        // Dashboard & Arsip
        Route::get('/dashboard/stats', [DashboardController::class, 'dashboardStats']);
        Route::get('/arsip-history', [ArsipController::class, 'index']);

        // 📄 ROUTE TEMPLATE (Akses Umum)
        Route::get('/templates', [TemplateController::class, 'index']);
        Route::get('/templates/{id}', [TemplateController::class, 'show']); // 🎯 1. Ambil spesifik (bisa file lama)
        Route::get('/templates/terbaru/{jenis_dokumen_id}', [TemplateController::class, 'getLatestByJenis']); // 🎯 2. Ambil otomatis terbaru berdasarkan jenisnya
        Route::get('/templates/{id}/variables', [TemplateController::class, 'getVariables']);

        // Draft
        Route::get('/drafts', [DraftController::class, 'index']);
        Route::get('/drafts/{id}', [DraftController::class, 'show']);
        Route::post('/drafts', [DraftController::class, 'store']);
        Route::put('/drafts/{id}', [DraftController::class, 'update']);
        Route::delete('/drafts/{id}', [DraftController::class, 'destroy']);
        Route::get('/drafts/{id}/generate', [DraftController::class, 'generateFromDraft']);

        // Siap Cetak
        Route::get('/siap-cetak', [SiapCetakController::class, 'index']);
        Route::post('/generate-docx', [TemplateController::class, 'generateDocx']);
        Route::post('/siap-cetak', [SiapCetakController::class, 'store']);
        Route::put('/siap-cetak/{id}', [SiapCetakController::class, 'update']);
        Route::put('/siap-cetak/{id}/status', [SiapCetakController::class, 'updateStatus']);
        Route::get('/siap-cetak/{id}/download', [SiapCetakController::class, 'download']);
        Route::post('/siap-cetak/{id}/upload', [ArsipController::class, 'uploadBukti']);
        Route::post('/siap-cetak/{id}/selesai', [ArsipController::class, 'selesaiSuratTugas']);
        Route::post('/siap-cetak/{id}/cetak-lagi', [ArsipController::class, 'cetakLagi']);

        // Dropdown Data
        Route::get('/jabatan', [JabatanController::class, 'index']);
        Route::get('/jabatan/{id}', [JabatanController::class, 'show']);
        Route::get('/bidang', [BidangController::class, 'index']);
        Route::get('/bidang/{id}', [BidangController::class, 'show']);
        Route::get('/karyawan', [KaryawanController::class, 'index']);
        Route::get('/karyawan/{id}', [KaryawanController::class, 'show']);

        // 🎯 ROUTE DOKUMEN (Get List Dokumen untuk Excel, Counter, dan Store)
        Route::get('/dokumen', [DokumenController::class, 'index']);
        Route::get('/next-counter', [DokumenController::class, 'nextCounter']);
        Route::get('/dokumen/next-counter', [DokumenController::class, 'nextCounter']);
        Route::post('/dokumen/store', [DokumenController::class, 'store']);
    });

    // =========================================================================
    // 🛠️ KELOMPOK 2: MASTER CREATION & MANAGEMENT (ADMIN & MASTER_ADMIN)
    // =========================================================================
    Route::middleware('role:master_admin,admin')->group(function () {
        Route::get('/monitoring-all', [MonitoringController::class, 'monitoringAll']);

        // Upload Template Baru
        Route::post('/templates', [TemplateController::class, 'upload']);

        // Tambah & Edit Data Master
        Route::post('/jabatan', [JabatanController::class, 'store']);
        Route::put('/jabatan/{id}', [JabatanController::class, 'update']);
        Route::post('/bidang', [BidangController::class, 'store']);
        Route::put('/bidang/{id}', [BidangController::class, 'update']);
        Route::post('/karyawan', [KaryawanController::class, 'store']);
        Route::put('/karyawan/{id}', [KaryawanController::class, 'update']);

        // Tambah & Edit Profil User Baru
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
    });

    // =========================================================================
    // ⛔ KELOMPOK 3: DESTRUCTIVE ACTIONS (HANYA MASTER_ADMIN!)
    // =========================================================================
    Route::middleware('role:master_admin')->group(function () {
        Route::delete('/dokumen/{id}', [DokumenController::class, 'destroy']);

        // Hapus Permanen Template & Data Master
        Route::delete('/templates/{id}', [TemplateController::class, 'destroy']);
        Route::delete('/jabatan/{id}', [JabatanController::class, 'destroy']);
        Route::delete('/bidang/{id}', [BidangController::class, 'destroy']);
        Route::delete('/karyawan/{id}', [KaryawanController::class, 'destroy']);

        // Hapus & Nonaktifkan User
        Route::delete('/users/{id}', [UserController::class, 'destroy']);
        Route::post('/users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::post('/arsip-history/{id}/toggle-aktif', [ArsipController::class, 'toggleAktif']);
    });

});