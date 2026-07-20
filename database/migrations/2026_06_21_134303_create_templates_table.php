<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('nama');         // Untuk nyimpen nama asli file (misal: Surat_Tugas.docx)
            $table->string('file_path');    // Untuk nyimpen jalur url file (misal: /templates/17189_Surat.docx)
            $table->string('ukuran')->nullable(); // Kolom ukuran file yang kemarin kelupaan
            $table->timestamps();           // Otomatis bikin kolom created_at & updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
