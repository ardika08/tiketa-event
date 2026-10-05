<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizers', function (Blueprint $table) {
            $table->string('nama_bank')->nullable()->after('deskripsi');
            $table->string('nomor_rekening')->nullable()->after('nama_bank');
            $table->string('nama_rekening')->nullable()->after('nomor_rekening');
        });
    }

    public function down(): void
    {
        Schema::table('organizers', function (Blueprint $table) {
            $table->dropColumn(['nama_bank', 'nomor_rekening', 'nama_rekening']);
        });
    }
};