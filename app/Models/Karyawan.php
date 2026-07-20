<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Karyawan extends Model
{
    use HasFactory;

    protected $table = 'karyawan'; // Menunjuk ke tabel karyawan milikmu

    protected $fillable = [
        'nama_lengkap',
        'jabatan_id',
        'bidang_id',
        'alamat',        // <-- Kolom baru dimasukkan ke sini
        'no_hp',         // <-- Kolom baru dimasukkan ke sini
        'email',         // <-- Kolom baru dimasukkan ke sini
        'status_aktif'
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'karyawan_id');
    }

    // 🔗 Relasi ke tabel Jabatan
    public function jabatan()
    {
        return $this->belongsTo(Jabatan::class, 'jabatan_id');
    }

    // 🔗 Relasi ke tabel Bidang
    public function bidang()
    {
        return $this->belongsTo(Bidang::class, 'bidang_id');
    }
}