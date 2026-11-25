<?php

namespace App\Jobs;

use App\Exceptions\MailketingRejected;
use App\Mail\TicketCancellationMail;
use App\Models\TicketCancellationNotification;
use App\Services\MailketingService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTicketCancellationEmail
{
    use Dispatchable;

    public function __construct(public int $notificationId) {}

    public function handle(MailketingService $mail): void
    {
        // An external send may never outlive a rolled-back claim.
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Cancellation delivery must run outside a transaction.');
        }

        $claim = TicketCancellationNotification::whereKey($this->notificationId)->where('status', 'pending');
        if ($claim->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1')]) !== 1) {
            return;
        }

        $notice = TicketCancellationNotification::findOrFail($this->notificationId);
        $outcome = TicketCancellationNotification::whereKey($notice->id)
            ->where('status', 'sending')->where('attempts', $notice->attempts);
        try {
            $mail->sendConfirmed(new TicketCancellationMail($notice->content), $notice->recipient, 'nontix-cancellation-'.$notice->message_id);
            $status = 'sent';
        } catch (MailketingRejected) {
            $status = 'failed';
        } catch (Throwable $error) {
            // A timeout or ambiguous provider response can mean the buyer already
            // received the email. Never retry blindly (message_id is not a provider idempotency guarantee).
            Log::warning('Cancellation email outcome uncertain.', ['notification_id' => $notice->id, 'exception' => $error::class]);
            $status = 'uncertain';
        }

        $outcome->update(['status' => $status, 'sent_at' => $status === 'sent' ? now() : null]);
    }
}
