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
        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            // Menghubungkan draf dengan ID template master-nya
            $table->foreignId('template_id')->constrained('templates')->onDelete('cascade');
            // Menyimpan nama identitas draf (contoh pada gambar: "hm - 22/6/2026")
            $table->string('nama_draft');
            // Menyimpan seluruh isian objek form dinamis (no, bulan, tahun, nama_penyerahan, dll) sebagai text JSON
            $table->json('data_input'); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drafts');
    }
};