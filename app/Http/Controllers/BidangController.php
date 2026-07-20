<?php

namespace App\Http\Controllers;

use App\Models\Bidang;
use Illuminate\Http\Request;

class BidangController extends Controller
{
    public function index() {
        return response()->json(['success' => true, 'data' => Bidang::all()]);
    }

    public function store(Request $request) {
        $request->validate(['nama_bidang' => 'required|string|unique:bidang,nama_bidang']);
        $bidang = Bidang::create($request->all());
        return response()->json(['success' => true, 'data' => $bidang], 201);
    }

    // ✏️ UPDATE PADA FUNGSI DESTROY
    public function destroy($id) {
        $bidang = Bidang::find($id);

        if (!$bidang) {
            return response()->json(['success' => false, 'message' => 'Data bidang tidak ditemukan'], 404);
        }

        try {
            $bidang->delete();
            return response()->json(['success' => true, 'message' => 'Bidang berhasil dihapus']);
        } catch (\Illuminate\Database\QueryException $e) {
            // Error code 23000 adalah integrity constraint violation (terikat foreign key)
            return response()->json([
                'success' => false, 
                'message' => 'Bidang tidak bisa dihapus karena masih digunakan oleh data karyawan!'
            ], 400);
        }
    }
}