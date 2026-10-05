<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->cascadeOnDelete();
            $table->string('kode_tiket')->unique();
            $table->string('nama_pemegang')->nullable();
            $table->enum('status_kehadiran', ['belum_hadir', 'hadir'])->default('belum_hadir');
            $table->dateTime('checkin_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kode_qr')->unique();
            $table->enum('status_kehadiran', ['belum_hadir', 'hadir'])->default('belum_hadir');
            $table->dateTime('checkin_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_passes');
        Schema::dropIfExists('tickets');
    }
};
