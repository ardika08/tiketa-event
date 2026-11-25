<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 64);
            $table->string('payload_hash', 64);
            $table->text('public_reason');
            $table->text('internal_note')->nullable();
            $table->json('targets');
            $table->string('status', 16);
            $table->string('message');
            $table->timestamps();
            $table->unique(['order_id', 'request_key']);
        });

        Schema::create('ticket_cancellation_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_cancellation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('recipient');
            $table->json('content');
            $table->uuid('message_id')->unique();
            $table->string('status', 16)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::table('ticket_passes', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_passes', fn (Blueprint $table) => $table->dropColumn('cancelled_at'));
        Schema::dropIfExists('ticket_cancellation_notifications');
        Schema::dropIfExists('ticket_cancellations');
    }
};
