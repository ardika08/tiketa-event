<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderPaidMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Invoice & E-Ticket '.$this->order->kode_order.' — Nontix')
            ->view('emails.order-paid')
            ->with([
                'order' => $this->order,
                'frontendUrl' => rtrim((string) config('nontix.frontend_url'), '/'),
            ]);
    }
}
