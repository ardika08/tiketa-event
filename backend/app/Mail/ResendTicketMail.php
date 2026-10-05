<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResendTicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Kirim Ulang E-Ticket '.$this->order->kode_order.' — Nontix')
            ->view('emails.resend-ticket')
            ->with([
                'order' => $this->order,
                'frontendUrl' => rtrim((string) config('nontix.frontend_url'), '/'),
            ]);
    }
}
