<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama',
        'file_path',
        'ukuran',
        'jenis_dokumen_id',
        'status_aktif', // 👈 Pastikan baris ini ditambahkan
    ];
}