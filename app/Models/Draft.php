<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Draft extends Model
{
    use HasFactory;

    // 🌟 SEMUA KOLOM YANG DIISI LEWAT Draft::create() WAJIB MASUK SINI
    protected $fillable = [
        'user_id',
        'template_id', 
        'nama_draft', 
        'no_dokumen', // 👈 Tambahkan ini agar nomor otomatis tersimpan
        'data_input'
    ];

    // 🚨 KUNCI SAKTI 2: Otomatis mengubah JSON database menjadi Array PHP biasa
    protected $casts = [
        'data_input' => 'array',
    ];

    // Relasi balik ke template
    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    // 🌟 RELASI BARU: Satu draf bisa memiliki banyak item barang (1 to Many)
    public function items()
    {
        return $this->hasMany(DokumenItem::class, 'dokumen_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}