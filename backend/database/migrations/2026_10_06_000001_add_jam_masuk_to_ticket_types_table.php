<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->time('jam_masuk_mulai')->nullable()->after('max_per_order');
            $table->time('jam_masuk_selesai')->nullable()->after('jam_masuk_mulai');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn(['jam_masuk_mulai', 'jam_masuk_selesai']);
        });
    }
};
