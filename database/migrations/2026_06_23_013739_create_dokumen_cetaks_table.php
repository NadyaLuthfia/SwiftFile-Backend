<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('dokumen_cetak', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('templates')->onDelete('cascade');
            $table->string('nama_dokumen');
            $table->json('variables'); // Menyimpan semua isi ketikan form dari React
            $table->enum('status', ['belum_dicetak', 'sudah_dicetak'])->default('belum_dicetak');
            $table->string('foto_ttd')->nullable(); // Lokasi simpan foto bukti ttd RL
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dokumen_cetaks');
    }
};
