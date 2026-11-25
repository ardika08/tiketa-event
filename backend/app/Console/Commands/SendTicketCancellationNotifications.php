<?php

namespace App\Console\Commands;

use App\Jobs\SendTicketCancellationEmail;
use App\Models\TicketCancellationNotification;
use Illuminate\Console\Command;

class SendTicketCancellationNotifications extends Command
{
    protected $signature = 'nontix:send-ticket-cancellation-notifications';

    protected $description = 'Process the durable outbox for ticket cancellation emails';

    public function handle(): int
    {
        // A crashed worker may have sent the email before dying. Leave those
        // records for provider reconciliation, not automatic delivery.
        TicketCancellationNotification::where('status', 'sending')
            ->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'uncertain']);

        foreach (TicketCancellationNotification::where('status', 'pending')->orderBy('id')->limit(5)->pluck('id') as $id) {
            SendTicketCancellationEmail::dispatchSync($id);
        }

        return self::SUCCESS;
    }
}
