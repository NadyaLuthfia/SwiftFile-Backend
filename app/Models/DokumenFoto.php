<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DokumenFoto extends Model
{
    use HasFactory;

    protected $table = 'dokumen_foto';

    protected $fillable = [
        'dokumen_id',
        'path_foto'
    ];

    // Kebalikan relasi: Foto ini milik sebuah dokumen
    public function dokumen()
    {
        return $this->belongsTo(Dokumen::class, 'dokumen_id', 'id');
    }
}