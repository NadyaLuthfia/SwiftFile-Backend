<?php

namespace App\Http\Controllers;

use App\Models\Jabatan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class JabatanController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Jabatan::all()
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_jabatan' => 'required|string|unique:jabatan,nama_jabatan'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Mempertahankan request->all() sesuai bawaan kamu
        $jabatan = Jabatan::create($request->all());

        return response()->json([
            'success' => true,
            'data' => $jabatan
        ], 201);
    }

    public function show($id)
    {
        $jabatan = Jabatan::find($id);

        if (!$jabatan) {
            return response()->json([
                'success' => false,
                'message' => 'Data jabatan tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $jabatan
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $jabatan = Jabatan::find($id);

        if (!$jabatan) {
            return response()->json([
                'success' => false,
                'message' => 'Data jabatan tidak ditemukan'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_jabatan' => 'sometimes|string|max:255|unique:jabatan,nama_jabatan,' . $id,
            'status_aktif' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->has('nama_jabatan')) {
            $jabatan->nama_jabatan = $request->nama_jabatan;
        }

        if ($request->has('status_aktif')) {
            $jabatan->status_aktif = $request->status_aktif;
        }

        $jabatan->save();

        return response()->json([
            'success' => true,
            'message' => 'Data jabatan berhasil diperbarui.',
            'data' => $jabatan
        ], 200);
    }

    public function destroy($id)
    {
        $jabatan = Jabatan::find($id);

        if (!$jabatan) {
            return response()->json([
                'success' => false,
                'message' => 'Data jabatan tidak ditemukan'
            ], 404);
        }

        try {
            $jabatan->delete();
            return response()->json([
                'success' => true,
                'message' => 'Jabatan berhasil dihapus'
            ], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            // Menangkap error foreign key jika jabatan masih dipake karyawan
            return response()->json([
                'success' => false,
                'message' => 'Jabatan tidak bisa dihapus karena masih digunakan oleh data karyawan!'
            ], 400);
        }
    }
}