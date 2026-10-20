<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak setiap email yang dikirim sistem.
 *
 * Tujuannya satu: ketika pembeli bilang "belum menerima email", partner bisa
 * menjawab dengan bukti (kapan dikirim, ke alamat apa, message_id apa) tanpa
 * harus grep log server. Sekaligus mencatat pemakaian "kirim ke email lain"
 * supaya fitur darurat itu tetap teraudit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('jenis', 40);          // invoice, resend, manual_alternative
            $table->string('tujuan', 150);
            // Diisi hanya kalau tujuannya BUKAN email pesanan (kirim manual).
            $table->string('email_pesanan', 150)->nullable();
            $table->string('subject')->nullable();
            $table->string('message_id')->nullable();
            $table->string('status', 20)->default('proses'); // proses, terkirim, gagal
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index(['jenis', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
