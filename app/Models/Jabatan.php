<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Jabatan extends Model
{
    use HasFactory;

    // 🌟 Kunci agar Laravel membaca tabel 'jabatan' (bukan jabatans)
    protected $table = 'jabatan';

    // 🌟 Daftarkan kolom yang boleh diisi lewat React
    protected $fillable = [
        'nama_jabatan',
        'status_aktif',
    ];
}