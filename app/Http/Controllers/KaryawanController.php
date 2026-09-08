<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class KaryawanController extends Controller
{
    public function index(Request $request)
    {
        $query = Karyawan::with(['jabatan', 'bidang', 'user'])
            ->orderBy('nama_lengkap', 'asc');

        // Filter berdasarkan status_aktif jika parameter status dikirim
        if ($request->filled('status')) {
            $query->where('status_aktif', $request->status);
        }

        $karyawan = $query->get();

        return response()->json([
            'success' => true,
            'data'    => $karyawan,
        ]);
    }

    public function store(Request $request)
    {
        // Validasi data karyawan dan akun user
        $request->validate([
            'nama_lengkap' => 'required|string|max:255',
            'jabatan_id'   => 'required|exists:jabatan,id',
            'bidang_id'    => 'required|exists:bidang,id',
            'alamat'       => 'nullable|string',
            'no_hp'        => 'nullable|string|max:20',
            'email'        => 'nullable|email|max:255',

            // Validasi akun jika 'buat_akun' bernilai true
            'username'     => 'required_if:buat_akun,true|nullable|string|max:255|unique:users,username',
            'password'     => 'required_if:buat_akun,true|nullable|string|min:6',
            'role'         => 'required_if:buat_akun,true|nullable|string|in:user,admin,master_admin',
        ]);

        DB::beginTransaction();

        try {
            // Step 1: Simpan data Karyawan
            $karyawan = Karyawan::create([
                'nama_lengkap' => $request->nama_lengkap,
                'jabatan_id'   => $request->jabatan_id,
                'bidang_id'    => $request->bidang_id,
                'alamat'       => $request->alamat,
                'no_hp'        => $request->no_hp,
                'email'        => $request->email,
                'status_aktif' => $request->get('status_aktif', 1),
            ]);

            // Step 2: Buat akun login jika dicentang
            if ($request->boolean('buat_akun')) {
                User::create([
                    'karyawan_id' => $karyawan->id,
                    'username'    => $request->username,
                    'password'    => Hash::make($request->password),
                    'role'        => $request->role,
                ]);
            }

            DB::commit();

            // Load ulang relasi agar respon utuh
            $karyawan->load(['jabatan', 'bidang', 'user']);

            return response()->json([
                'success' => true,
                'data'    => $karyawan,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $karyawan = Karyawan::with('user')->find($id);

        if (!$karyawan) {
            return response()->json([
                'success' => false,
                'message' => 'Data karyawan tidak ditemukan',
            ], 404);
        }

        $userIdLama = $karyawan->user ? $karyawan->user->id : 'NULL';

        $request->validate([
            'nama_lengkap' => 'required|string|max:255',
            'jabatan_id'   => 'required|exists:jabatan,id',
            'bidang_id'    => 'required|exists:bidang,id',
            'alamat'       => 'nullable|string',
            'no_hp'        => 'nullable|string|max:20',
            'email'        => 'nullable|email|max:255',
            'status_aktif' => 'nullable|in:0,1',

            'username'     => 'required_if:buat_akun,true|nullable|string|max:255|unique:users,username,' . $userIdLama,
            'password'     => 'nullable|string|min:6',
            'role'         => 'required_if:buat_akun,true|nullable|string|in:user,admin,master_admin',
        ]);

        DB::beginTransaction();

        try {
            // Step 1: Update data karyawan
            $karyawan->update([
                'nama_lengkap' => $request->nama_lengkap,
                'jabatan_id'   => $request->jabatan_id,
                'bidang_id'    => $request->bidang_id,
                'alamat'       => $request->alamat,
                'no_hp'        => $request->no_hp,
                'email'        => $request->email,
                'status_aktif' => $request->get('status_aktif', $karyawan->status_aktif),
            ]);

            // Step 2: Kelola data akun login
            if ($request->boolean('buat_akun')) {
                if (!$karyawan->user) {
                    User::create([
                        'karyawan_id' => $karyawan->id,
                        'username'    => $request->username,
                        'password'    => Hash::make($request->password),
                        'role'        => $request->role,
                    ]);
                } else {
                    $dataUserUpdate = [
                        'username' => $request->username,
                        'role'     => $request->role,
                    ];

                    if ($request->filled('password')) {
                        $dataUserUpdate['password'] = Hash::make($request->password);
                    }

                    $karyawan->user->update($dataUserUpdate);
                }
            }

            DB::commit();

            $karyawan->load(['jabatan', 'bidang', 'user']);

            return response()->json([
                'success' => true,
                'message' => 'Data karyawan dan akun berhasil diperbarui',
                'data'    => $karyawan,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah data: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $karyawan = Karyawan::find($id);

        if (!$karyawan) {
            return response()->json([
                'success' => false,
                'message' => 'Data karyawan tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            // Nonaktifkan karyawan (status_aktif = 0)
            $karyawan->update([
                'status_aktif' => 0,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Karyawan berhasil dinonaktifkan, data tetap aman di database!',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menonaktifkan data: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function forceDelete($id)
    {
        $karyawan = Karyawan::find($id);

        if (!$karyawan) {
            return response()->json([
                'success' => false,
                'message' => 'Data karyawan tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            // Hapus user terkait jika ada
            if ($karyawan->user) {
                $karyawan->user->delete();
            }

            // Hapus permanen karyawan
            if (method_exists($karyawan, 'forceDelete')) {
                $karyawan->forceDelete();
            } else {
                $karyawan->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data karyawan dan akun terkait berhasil dihapus secara permanen!',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data secara permanen: ' . $e->getMessage(),
            ], 500);
        }
    }
}