<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_session_ticket_type', function (Blueprint $table) {
            $table->dateTime('checkin_mulai')->nullable();
            $table->dateTime('checkin_selesai')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_session_ticket_type', function (Blueprint $table) {
            $table->dropColumn(['checkin_mulai', 'checkin_selesai']);
        });
    }
};
