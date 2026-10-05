<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('nama_tiket');
            $table->decimal('harga', 14, 2)->default(0);
            $table->unsignedInteger('kuota')->default(0);
            $table->unsignedInteger('sisa_kuota')->default(0);
            $table->unsignedInteger('max_per_order')->default(4);
            $table->string('gambar_url')->nullable();
            $table->boolean('is_bundle')->default(false);
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();
        });

        Schema::create('event_session_ticket_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_session_id')->constrained()->cascadeOnDelete();
            $table->unique(['ticket_type_id', 'event_session_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_session_ticket_type');
        Schema::dropIfExists('ticket_types');
    }
};
