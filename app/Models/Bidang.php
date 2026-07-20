<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bidang extends Model
{
    use HasFactory;

    // 🌟 Kunci agar Laravel membaca tabel 'bidang' (bukan bidangs)
    protected $table = 'bidang';

    // 🌟 Daftarkan kolom yang boleh diisi lewat React
    protected $fillable = ['nama_bidang'];
}