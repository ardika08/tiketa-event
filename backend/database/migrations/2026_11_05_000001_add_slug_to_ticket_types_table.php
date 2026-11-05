<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Link checkout langsung: /checkout/{eventSlug}/{ticketSlug}.
 * Slug unik dalam satu event; nama tiket sama tetap boleh pada event lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('nama_tiket');
        });

        // Backfill tiket lama sebelum memasang unique index komposit.
        DB::table('ticket_types')->orderBy('event_id')->orderBy('id')->each(function (object $ticket): void {
            $base = Str::slug($ticket->nama_tiket) ?: 'tiket';
            $slug = $base;
            $suffix = 2;

            while (DB::table('ticket_types')
                ->where('event_id', $ticket->event_id)
                ->where('slug', $slug)
                ->exists()) {
                $slug = $base.'-'.$suffix++;
            }

            DB::table('ticket_types')->where('id', $ticket->id)->update(['slug' => $slug]);
        });

        Schema::table('ticket_types', function (Blueprint $table) {
            $table->unique(['event_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
