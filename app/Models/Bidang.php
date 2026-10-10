<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bidang extends Model
{
    use HasFactory;

    protected $table = 'bidang';
    protected $primaryKey = 'id';

    // 🌟 Cukup gunakan status_aktif saja
    protected $fillable = [
        'nama_bidang',
        'status_aktif',
    ];

    public function karyawan()
    {
        return $this->hasMany(Karyawan::class, 'id_bidang', 'id');
    }
}