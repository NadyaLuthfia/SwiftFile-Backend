<?php

namespace App\Http\Controllers;

use App\Models\Jabatan;
use Illuminate\Http\Request;

class JabatanController extends Controller
{
    public function index() {
        return response()->json(['success' => true, 'data' => Jabatan::all()]);
    }

    public function store(Request $request) {
        $request->validate(['nama_jabatan' => 'required|string|unique:jabatan,nama_jabatan']);
        $bidang = Jabatan::create($request->all()); // Tetap pertahankan request all bawaanmu
        return response()->json(['success' => true, 'data' => $bidang], 201);
    }

    // ✏️ UPDATE PADA FUNGSI DESTROY JABATAN
    public function destroy($id) {
        $jabatan = Jabatan::find($id);

        if (!$jabatan) {
            return response()->json(['success' => false, 'message' => 'Data jabatan tidak ditemukan'], 404);
        }

        try {
            $jabatan->delete();
            return response()->json(['success' => true, 'message' => 'Jabatan berhasil dihapus']);
        } catch (\Illuminate\Database\QueryException $e) {
            // Menangkap error foreign key jika jabatan masih nempel di karyawan
            return response()->json([
                'success' => false, 
                'message' => 'Jabatan tidak bisa dihapus karena masih digunakan oleh data karyawan!'
            ], 400);
        }
    }
}