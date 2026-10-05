<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nama_penyelenggara');
            $table->string('email')->nullable();
            $table->string('telepon')->nullable();
            $table->string('logo_url')->nullable();
            $table->text('deskripsi')->nullable();
            $table->enum('status_verifikasi', ['pending', 'terverifikasi', 'ditolak'])->default('pending');
            $table->enum('status_akun', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();
        });

        Schema::create('organizer_legal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained()->cascadeOnDelete();
            $table->string('nama_penanggung_jawab')->nullable();
            $table->string('no_identitas')->nullable();
            $table->string('dokumen_url')->nullable();
            $table->string('tipe_dokumen')->nullable();
            $table->enum('status', ['pending', 'terverifikasi', 'ditolak'])->default('pending');
            $table->text('catatan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_legal');
        Schema::dropIfExists('organizers');
    }
};
