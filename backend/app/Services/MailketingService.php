<?php

namespace App\Services;

use App\Exceptions\MailketingRejected;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MailketingService
{
    public function isLive(): bool
    {
        return config('nontix.mailketing.mode') === 'live';
    }

    /**
     * Kirim email melalui Mailketing API v2 (live) atau tahan + catat (fake).
     * Mailable tetap menjadi sumber subject + view.
     * Jika API gagal, otomatis fallback ke SMTP (Laravel Mail).
     */
    public function send(Mailable $mailable, string $to, ?string $messageId = null): void
    {
        if (! $this->isLive()) {
            Log::info('[Mailketing:fake] Email ditahan (mode fake).', [
                'to' => $to,
                'mailable' => $mailable::class,
            ]);

            return;
        }

        try {
            $this->sendViaApi($mailable, $to, $messageId);
        } catch (\Throwable $e) {
            Log::error('[Mailketing:api] Gagal kirim via API, fallback ke SMTP.', [
                'to' => $to,
                'mailable' => $mailable::class,
                'error' => $e->getMessage(),
            ]);

            Mail::to($to)->send($mailable);

            Log::warning('[Mailketing:smtp] Fallback SMTP terkirim.', [
                'to' => $to,
                'mailable' => $mailable::class,
            ]);
        }
    }

    /** Require a provider acknowledgment; no simulated send or SMTP fallback. */
    public function sendConfirmed(Mailable $mailable, string $to, string $messageId): void
    {
        if (! $this->isLive()) {
            throw new MailketingRejected('Mailketing live mode is disabled.');
        }

        $this->sendViaApi($mailable, $to, $messageId, true);
    }

    private function sendViaApi(Mailable $mailable, string $to, ?string $messageId, bool $confirmedOnly = false): void
    {
        $baseUrl = rtrim((string) config('nontix.mailketing.base_url'), '/');
        $token = (string) config('nontix.mailketing.api_token');

        if ($token === '') {
            throw new MailketingRejected('MAILKETING_API_TOKEN belum diisi.');
        }

        try {
            $content = $mailable->render();
        } catch (\Throwable $error) {
            throw new MailketingRejected('Template email gagal dirender.', previous: $error);
        }
        $subject = $mailable->subject ?: '(Tanpa Subjek)';
        $sentMessageId = $messageId ?? 'nontix-'.Str::random(12);

        $response = Http::timeout(20)
            ->withHeaders(['X-Api-Token' => $token])
            ->acceptJson()
            ->withoutRedirecting()
            ->post($baseUrl.'/api/v2/send', [
                'from_name' => (string) config('mail.from.name'),
                'from_email' => (string) config('mail.from.address'),
                'subject' => $subject,
                'recipient' => $to,
                'content' => $content,
                'message_id' => $sentMessageId,
            ]);

        if (! $response->successful()) {
            if ($confirmedOnly && in_array($response->status(), [400, 401, 402, 403, 404, 405, 413, 422, 429], true)) {
                throw new MailketingRejected('Mailketing rejected the message.');
            }

            throw new \RuntimeException('HTTP '.$response->status().': '.Str::limit($response->body(), 300));
        }

        $body = $response->json();
        if ($confirmedOnly && ($body['success'] ?? null) === false) {
            throw new MailketingRejected('Mailketing rejected the message.');
        }
        if (($body['success'] ?? false) !== true) {
            throw new \RuntimeException('API gagal: '.($body['message'] ?? 'respons tidak dikenal'));
        }

        if ($confirmedOnly) {
            return;
        }

        Log::info('[Mailketing:api] Email terkirim.', [
            'to' => $to,
            'mailable' => $mailable::class,
            'message_id' => $sentMessageId,
            'subject' => $subject,
            'content_length' => strlen($content),
            'api_response' => Str::limit($response->body(), 300),
        ]);
    }
}
