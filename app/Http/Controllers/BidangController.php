<?php

namespace App\Http\Controllers;

use App\Models\Bidang;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

class BidangController extends Controller
{
    // 1. Ambil semua data bidang
    public function index() 
    {
        return response()->json([
            'success' => true, 
            'data' => Bidang::all()
        ], 200);
    }

    // 2. Tambah data bidang baru
    public function store(Request $request) 
    {
        $request->validate([
            'nama_bidang' => 'required|string|unique:bidang,nama_bidang|max:255'
        ], [
            'nama_bidang.required' => 'Nama bidang wajib diisi.',
            'nama_bidang.unique'   => 'Nama bidang sudah ada.',
            'nama_bidang.max'      => 'Nama bidang maksimal 255 karakter.'
        ]);

        $bidang = Bidang::create([
            'nama_bidang'  => $request->nama_bidang,
            'status_aktif' => $request->input('status_aktif', 1),
            'status'       => $request->input('status', 'Aktif')
        ]);

        return response()->json([
            'success' => true, 
            'message' => 'Bidang berhasil ditambahkan',
            'data'    => $bidang
        ], 201);
    }

    // 3. Detail satu data bidang
    public function show($id) 
    {
        $bidang = Bidang::find($id);

        if (!$bidang) {
            return response()->json([
                'success' => false, 
                'message' => 'Data bidang tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true, 
            'data'    => $bidang
        ], 200);
    }

    // 4. Update data bidang (Termasuk Ubah Status & Nama Bidang)
    public function update(Request $request, $id) 
    {
        $bidang = Bidang::find($id);

        if (!$bidang) {
            return response()->json([
                'success' => false, 
                'message' => 'Data bidang tidak ditemukan'
            ], 404);
        }

        // Menggunakan 'sometimes' agar validasi nama_bidang tidak gagal 
        // ketika frontend hanya mengirimkan payload status_aktif & status
        $request->validate([
            'nama_bidang'  => 'sometimes|required|string|max:255|unique:bidang,nama_bidang,' . $id,
            'status_aktif' => 'nullable',
            'status'       => 'nullable|string'
        ], [
            'nama_bidang.required' => 'Nama bidang wajib diisi.',
            'nama_bidang.unique'   => 'Nama bidang sudah ada.',
            'nama_bidang.max'      => 'Nama bidang maksimal 255 karakter.'
        ]);

        // Simpan perubahan ke database
        $bidang->update([
            'nama_bidang'  => $request->input('nama_bidang', $bidang->nama_bidang),
            'status_aktif' => $request->has('status_aktif') ? $request->status_aktif : $bidang->status_aktif,
            'status'       => $request->has('status') ? $request->status : $bidang->status,
        ]);

        return response()->json([
            'success' => true, 
            'message' => 'Bidang berhasil diperbarui',
            'data'    => $bidang
        ], 200);
    }

    // 5. Hapus data bidang
    public function destroy($id) 
    {
        $bidang = Bidang::find($id);

        if (!$bidang) {
            return response()->json([
                'success' => false, 
                'message' => 'Data bidang tidak ditemukan'
            ], 404);
        }

        try {
            $bidang->delete();
            return response()->json([
                'success' => true, 
                'message' => 'Bidang berhasil dihapus'
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Bidang tidak bisa dihapus karena masih digunakan oleh data lain!'
            ], 400);
        }
    }
}