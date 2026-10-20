<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan manual (OTS / di lokasi / transfer langsung).
 *
 * Order seperti ini tidak lewat gateway, jadi tidak ada verifikasi uang masuk
 * dari pihak ketiga. Yang bisa dilakukan sistem: menandai sumbernya, mencatat
 * siapa yang membuat, metode bayarnya, dan catatan bebas — supaya laporan bisa
 * memisahkan penjualan gateway dari penjualan manual saat diaudit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // online = lewat gateway, manual = diinput partner.
            $table->string('sumber', 20)->default('online')->after('status')->index();
            // tunai, transfer, bayar_di_lokasi — hanya keterangan, bukan gateway.
            $table->string('metode_bayar', 30)->nullable()->after('sumber');
            $table->text('catatan_manual')->nullable()->after('metode_bayar');
            // Partner yang menginput. Penting: satu-satunya jejak "siapa".
            $table->foreignId('created_by')->nullable()->after('catatan_manual')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['sumber', 'metode_bayar', 'catatan_manual', 'created_by']);
        });
    }
};
