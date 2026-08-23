<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dokumen extends Model
{
    use HasFactory;

    // 1. Kasih tahu Laravel kalau model ini memegang table 'dokumen'
    protected $table = 'dokumen';

    // 2. Daftarkan kolom wajib yang nanti bakal diisi pas submit form
    protected $fillable = [
        'template_id',
        'user_id',
        'jenis_dokumen_id',
        'nomor_dokumen',
        'jenis', 
        'tanggal_dokumen',
        'penyerah_id',
        'penerima_id',
        'catatan',
        'kolom_tambahan',
        'file_word',
        'tanggal_cetak',
        'status_aktif'
    ];

    // 🌟 TAMBAHKAN INI DI BAWAH FILLABLE BIAR REPTIL-JSON AMAN
    protected $casts = [
        'variables' => 'array',
    ];

    // 3. RELASI: Hubungkan ke table 'dokumen_item' (Anak ke-1)
    public function items()
    {
        return $this->hasMany(DokumenItem::class, 'dokumen_id', 'id');
    }

    // 4. RELASI: Hubungkan ke table 'dokumen_foto' (Anak ke-2)
    public function fotos()
    {
        return $this->hasMany(DokumenFoto::class, 'dokumen_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}