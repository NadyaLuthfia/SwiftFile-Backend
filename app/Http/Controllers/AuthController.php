<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // 1. Validasi input dari React
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ], [
            'username.required' => 'Username wajib diisi ya!',
            'password.required' => 'Password-nya jangan lupa diisi!',
        ]);

        // 2. Cari user berdasarkan username sekalian angkut data karyawan-nya (Eager Loading)
        $user = User::with('karyawan')->where('username', $request->username)->first();

        // 3. Cek apakah user ada dan password-nya cocok
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Aduh, username atau password lu salah nih. Coba cek lagi!'
            ], 401);
        }

        // 4. BARU: Cek apakah karyawan tersebut berstatus aktif
        // Jika data karyawan tidak ditemukan ATAU status_aktif bernilai 0, kunci pintunya!
        if (!$user->karyawan || $user->karyawan->status_aktif != 1) {
            return response()->json([
                'message' => 'Akun Anda ditangguhkan atau sudah tidak aktif. Hubungi admin ya!'
            ], 403); // Status code 403 artinya Forbidden/Akses Ditolak
        }

        // 5. Bikin token keamanan pakai Sanctum (bawaan dari php artisan install:api)
        $token = $user->createToken('auth_token')->plainTextToken;

        // 6. Kirim data sukses balik ke React (Nama diambil dari tabel karyawan)
        return response()->json([
            'token' => $token,
            'user' => [
                'nama' => $user->karyawan->nama, // Mengambil nama dari tabel karyawan lewat relasi
                'role' => $user->role,
            ]
        ], 200);
    }
}