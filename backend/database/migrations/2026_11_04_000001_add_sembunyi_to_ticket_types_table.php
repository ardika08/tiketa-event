<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiket tersembunyi: tetap dijual, tapi tidak tampil di halaman publik.
 *
 * Kebutuhan partner: tiket khusus (undangan / komplementer / OTS) dijual lewat
 * penjualan manual, tapi tidak boleh muncul di halaman event publik. Kolom
 * `status` tidak bisa dipakai karena nonaktif juga MENOLAK penjualan — padahal
 * tiket sembunyi ini justru HARUS bisa dijual (manual).
 *
 * Pisah dari status supaya tiga kombinasi tetap mungkin:
 *   aktif  + tampak  = jualan normal
 *   aktif  + sembunyi = jualan manual saja (kasus ini)
 *   nonaktif          = berhenti dijual sama sekali
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->boolean('sembunyi')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('sembunyi');
        });
    }
};
