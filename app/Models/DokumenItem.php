<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DokumenItem extends Model
{
    use HasFactory;

    // 🌟 Tentukan nama tabelnya secara eksplisit karena kita pakai gaya snake_case tunggal
    protected $table = 'dokumen_item';

    // Kolom yang diizinkan untuk diisi massal
    protected $fillable = [
        'dokumen_id',
        'nama_item',
        'spesifikasi',
        'jumlah',
        'keterangan'
    ];

    // Relasi balik ke model Draft
    public function draft()
    {
        return $this->belongsTo(Draft::class, 'dokumen_id');
    }
}