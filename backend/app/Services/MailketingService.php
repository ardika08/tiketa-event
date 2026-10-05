<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailketingService
{
    public function isLive(): bool
    {
        return config('nontix.mailketing.mode') === 'live';
    }

    /**
     * Kirim email melalui Mailketing SMTP (live) atau tahan + catat (fake).
     */
    public function send(Mailable $mailable, string $to): void
    {
        if (! $this->isLive()) {
            Log::info('[Mailketing:fake] Email ditahan (mode fake).', [
                'to' => $to,
                'mailable' => $mailable::class,
            ]);

            return;
        }

        Mail::to($to)->send($mailable);
    }
}
