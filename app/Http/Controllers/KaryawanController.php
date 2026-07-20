<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\User; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash; 
use Illuminate\Support\Facades\DB;   

class KaryawanController extends Controller
{
    public function index(Request $request)
    {
        // 🔍 Membaca parameter ?status= dari React. Jika tidak dikirim, default-nya '1' (Aktif)
        $status = $request->query('status', '1');

        // Mengambil karyawan yang sesuai dengan status_aktif yang dicari
        $karyawan = Karyawan::with(['jabatan', 'bidang', 'user'])
            ->where('status_aktif', $status)
            ->orderBy('nama_lengkap', 'asc')
            ->get();

        return response()->json(['success' => true, 'data' => $karyawan]);
    }

    public function store(Request $request)
    {
        // 🛡️ Validasi data karyawan DAN data akun user
        $request->validate([
            'nama_lengkap' => 'required|string|max:255',
            'jabatan_id'   => 'required|exists:jabatan,id',
            'bidang_id'    => 'required|exists:bidang,id',
            'alamat'       => 'nullable|string',
            'no_hp'        => 'nullable|string|max:20',
            'email'        => 'nullable|email|max:255',
            
            // Validasi akun jika checkbox 'buat_akun' bernilai true
            'username'     => 'required_if:buat_akun,true|nullable|string|max:255|unique:users,username',
            'password'     => 'required_if:buat_akun,true|nullable|string|min:6',
            'role'         => 'required_if:buat_akun,true|nullable|string|in:user,admin,master_admin',
        ]);

        // 🔒 Transaksi database dimulai
        DB::beginTransaction();

        try {
            // STEP 1: Buat data Karyawan terlebih dahulu (karena kita butuh ID-nya)
            $karyawan = Karyawan::create([
                'nama_lengkap' => $request->nama_lengkap,
                'jabatan_id'   => $request->jabatan_id,
                'bidang_id'    => $request->bidang_id,
                'alamat'       => $request->alamat,
                'no_hp'        => $request->no_hp,
                'email'        => $request->email,
                'status_aktif' => $request->get('status_aktif', 1), 
            ]);

            // STEP 2: Jika admin mencentang checkbox "Buatkan Akses Login"
            if ($request->buat_akun == true || $request->buat_akun == 'true') {
                User::create([
                    'karyawan_id'  => $karyawan->id, // Ikat ID karyawan baru ke sini
                    'username'     => $request->username,
                    'password'     => Hash::make($request->password), 
                    'role'         => $request->role,
                ]);
            }

            // Sahkan penyimpanan data ke MySQL
            DB::commit(); 
            
            // Load kembali semua relasi agar React menerima data utuh terbaru
            $karyawan->load(['jabatan', 'bidang', 'user']);

            return response()->json(['success' => true, 'data' => $karyawan], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Gagal menyimpan data: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        // Cari data karyawan beserta data akun user lamanya (jika ada)
        $karyawan = Karyawan::with('user')->find($id);

        if (!$karyawan) {
            return response()->json(['success' => false, 'message' => 'Data karyawan tidak ditemukan'], 404);
        }

        // Ambil ID user lama (jika ada) untuk pengecualian validasi unique username
        $userIdLama = $karyawan->user ? $karyawan->user->id : 'NULL';

        // 🛡️ Validasi data edit (status_aktif dibuat nullable agar fleksibel)
        $request->validate([
            'nama_lengkap' => 'required|string|max:255',
            'jabatan_id'   => 'required|exists:jabatan,id',
            'bidang_id'    => 'required|exists:bidang,id',
            'alamat'       => 'nullable|string',
            'no_hp'        => 'nullable|string|max:20',
            'email'        => 'nullable|email|max:255',
            'status_aktif' => 'nullable|in:0,1',
            
            'username'     => 'required_if:buat_akun,true|nullable|string|max:255|unique:users,username,'.$userIdLama,
            'password'     => 'nullable|string|min:6', 
            'role'         => 'required_if:buat_akun,true|nullable|string|in:user,admin,master_admin',
        ]);

        DB::beginTransaction();

        try {
            // STEP 1: Update data fisik karyawan
            $karyawan->update([
                'nama_lengkap' => $request->nama_lengkap,
                'jabatan_id'   => $request->jabatan_id,
                'bidang_id'    => $request->bidang_id,
                'alamat'       => $request->alamat,
                'no_hp'        => $request->no_hp,
                'email'        => $request->email,
                // Jika status_aktif absen dari React, pakai nilai lama di database
                'status_aktif' => $request->get('status_aktif', $karyawan->status_aktif),
            ]);

            // STEP 2: Kelola data akun loginnya
            if ($request->buat_akun == true || $request->buat_akun == 'true') {
                if (!$karyawan->user) {
                    // Opsi A: Dulu GAK PUNYA AKUN, sekarang baru mau DIBUATKAN
                    User::create([
                        'karyawan_id' => $karyawan->id,
                        'username'    => $request->username,
                        'password'    => Hash::make($request->password),
                        'role'        => $request->role,
                    ]);
                } else {
                    // Opsi B: Memang SUDAH PUNYA AKUN, tinggal kita UPDATE data akunnya
                    $dataUserUpdate = [
                        'username' => $request->username,
                        'role'     => $request->role,
                    ];

                    // Hanya ganti password jika kolom password diisi oleh admin
                    if ($request->filled('password')) {
                        $dataUserUpdate['password'] = Hash::make($request->password);
                    }

                    $karyawan->user->update($dataUserUpdate);
                }
            }

            DB::commit();

            // Load ulang relasi sebelum dilempar balik ke React
            $karyawan->load(['jabatan', 'bidang', 'user']);

            return response()->json(['success' => true, 'message' => 'Data karyawan dan akun berhasil diperbarui', 'data' => $karyawan]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Gagal mengubah data: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $karyawan = Karyawan::find($id);

        if (!$karyawan) {
            return response()->json(['success' => false, 'message' => 'Data karyawan tidak ditemukan'], 404);
        }

        DB::beginTransaction();
        try {
            // 🛠️ AMAN: Hanya diubah status_aktif-nya jadi 0 (nonaktif), tidak membumihanguskan data
            $karyawan->update([
                'status_aktif' => 0
            ]);

            DB::commit();
            return response()->json([
                'success' => true, 
                'message' => 'Karyawan berhasil dinonaktifkan, data tetap aman di database!'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Gagal menonaktifkan data: ' . $e->getMessage()], 500);
        }
    }
}