<?php

namespace App\Services;

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

    private function sendViaApi(Mailable $mailable, string $to, ?string $messageId): void
    {
        $baseUrl = rtrim((string) config('nontix.mailketing.base_url'), '/');
        $token = (string) config('nontix.mailketing.api_token');

        if ($token === '') {
            throw new \RuntimeException('MAILKETING_API_TOKEN belum diisi.');
        }

        $content = $mailable->render();
        $subject = $mailable->subject ?: '(Tanpa Subjek)';
        $sentMessageId = $messageId ?? 'nontix-'.Str::random(12);

        $response = Http::timeout(20)
            ->withHeaders(['X-Api-Token' => $token])
            ->acceptJson()
            ->post($baseUrl.'/api/v2/send', [
                'from_name' => (string) config('mail.from.name'),
                'from_email' => (string) config('mail.from.address'),
                'subject' => $subject,
                'recipient' => $to,
                'content' => $content,
                'message_id' => $sentMessageId,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status().': '.Str::limit($response->body(), 300));
        }

        $body = $response->json();
        if (($body['success'] ?? false) !== true) {
            throw new \RuntimeException('API gagal: '.($body['message'] ?? 'respons tidak dikenal'));
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
