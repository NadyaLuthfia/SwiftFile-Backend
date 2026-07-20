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
        Schema::create('dokumen_item', function (Blueprint $table) {
            $table->id();
            
            // 🌟 Menghubungkan item ke id di tabel drafts ($draftBaru->id)
            $table->foreignId('dokumen_id')->constrained('drafts')->onDelete('cascade');
            
            $table->string('nama_item');          // Menampung nama barang
            $table->string('spesifikasi')->nullable(); // Menampung spek/merk barang
            $table->integer('jumlah')->default(0);    // Menampung jumlah unit
            $table->text('keterangan')->nullable();   // Menampung rangkuman kalimat log otomatis
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dokumen_item');
    }
};