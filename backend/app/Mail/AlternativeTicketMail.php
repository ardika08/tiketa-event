<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * E-ticket yang dikirim ke alamat SELAIN email pesanan.
 *
 * Dipakai saat pembeli melaporkan emailnya tidak sampai (kasus nyata: alamat
 * @outlook.com yang tidak masuk inbox maupun Junk). Subjeknya sengaja dibedakan
 * supaya jelas ini bukan kiriman otomatis pesanan, dan isinya menyebut alamat
 * pesanan aslinya — supaya penerima tahu kenapa ia menerima tiket ini.
 */
class AlternativeTicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public string $emailPesanan)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('E-Ticket '.$this->order->kode_order.' — Nontix')
            ->view('emails.resend-ticket')
            ->with([
                'order' => $this->order,
                'frontendUrl' => rtrim((string) config('nontix.frontend_url'), '/'),
                'emailTujuanAlternatif' => true,
                'emailPesanan' => $this->emailPesanan,
            ]);
    }
}
