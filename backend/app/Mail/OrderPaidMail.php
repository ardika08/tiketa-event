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
        // Order manual tidak pernah lewat gateway: subjek dan isinya tidak boleh
        // mengklaim "Pembayaran Berhasil" / "Invoice". Pembeli membayar tunai
        // atau transfer ke panitia, jadi yang dikirim adalah e-ticket saja.
        $manual = ! $this->order->lunasLewatGateway();

        return $this
            ->subject(($manual ? 'E-Ticket ' : 'Invoice & E-Ticket ').$this->order->kode_order.' — Nontix')
            ->view($manual ? 'emails.ticket-manual' : 'emails.order-paid')
            ->with([
                'order' => $this->order,
                'frontendUrl' => rtrim((string) config('nontix.frontend_url'), '/'),
            ]);
    }
}
