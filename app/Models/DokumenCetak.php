<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DokumenCetak extends Model
{
    use HasFactory;

    // Menentukan nama tabel di database secara eksplisit
    protected $table = 'dokumen_cetak';

    // Kolom yang boleh diisi lewat API / Request
    protected $fillable = [
        'user_id',
        'template_id',
        'nama_dokumen',
        'variables',     // Sesuaikan dengan nama kolom di database kamu (variables / data_input)
        'history_perubahan',
        'file_path',
        'status_aktif'
    ];

    protected $casts = [
        'variables' => 'array'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function template()
    {
        // Relasi ini ngasih tau kalau dokumen cetak ini milik sebuah template
        return $this->belongsTo(Template::class, 'template_id');
    }
}