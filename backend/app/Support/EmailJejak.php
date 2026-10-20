<?php

namespace App\Support;

use App\Models\EmailLog;
use App\Models\Order;
use App\Services\MailketingService;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Satu pintu untuk mengirim email pesanan + mencatat jejaknya.
 *
 * Dipakai tiga jalur: invoice setelah lunas, kirim ulang oleh pembeli/partner,
 * dan kirim manual ke alamat lain (kasus email pembeli tidak sampai).
 *
 * Catatan status: Mailketing hanya menjawab "queued". Jadi `terkirim` di sini
 * berarti "diterima antrean Mailketing", bukan "sudah masuk inbox pembeli" —
 * itu di luar kendali kita dan tidak bisa dibuktikan dari sisi server.
 */
class EmailJejak
{
    public function __construct(private MailketingService $mail)
    {
    }

    public function kirim(
        Mailable $mailable,
        Order $order,
        string $tujuan,
        string $jenis,
        ?int $userId = null,
    ): EmailLog {
        $log = EmailLog::create([
            'order_id' => $order->id,
            'event_id' => $order->event_id,
            'jenis' => $jenis,
            'tujuan' => $tujuan,
            'email_pesanan' => $order->email,
            'message_id' => 'nontix-'.Str::random(12),
            'status' => EmailLog::STATUS_PROSES,
            'user_id' => $userId,
        ]);

        try {
            $this->mail->send($mailable, $tujuan, $log->message_id);
        } catch (Throwable $e) {
            $log->update([
                'status' => EmailLog::STATUS_GAGAL,
                'error' => Str::limit($e->getMessage(), 500),
                'subject' => $mailable->subject,
            ]);

            throw $e;
        }

        $log->update([
            'status' => EmailLog::STATUS_TERKIRIM,
            'subject' => $mailable->subject,
        ]);

        return $log;
    }
}
