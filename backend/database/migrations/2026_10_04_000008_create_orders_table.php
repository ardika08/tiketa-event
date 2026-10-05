<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('kode_order')->unique();
            $table->string('nama_pembeli');
            $table->string('email');
            $table->string('whatsapp');
            $table->string('instagram')->nullable();
            $table->string('tiktok')->nullable();
            $table->string('threads')->nullable();
            $table->json('form_data')->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('diskon', 14, 2)->default(0);
            $table->decimal('total_harga', 14, 2)->default(0);
            $table->decimal('biaya_layanan', 14, 2)->default(0);
            $table->enum('status', ['pending', 'lunas', 'dibatalkan', 'kadaluarsa'])->default('pending');
            $table->dateTime('batas_bayar')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'batas_bayar']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('jumlah');
            $table->decimal('harga_satuan', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
