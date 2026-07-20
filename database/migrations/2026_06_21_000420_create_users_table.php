<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan perintah untuk membuat tabel.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id(); // Membuat primary key otomatis (id)
            $table->string('username'); // Kolom username (Sudah sesuai request!)
            $table->string('password'); // Kolom password
            $table->string('role');     // Kolom role (user, admin, master_admin)
            $table->timestamps();       // Kolom otomatis 'created_at' & 'updated_at'
        });
    }

    /**
     * Batalkan perintah (jika di-rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};