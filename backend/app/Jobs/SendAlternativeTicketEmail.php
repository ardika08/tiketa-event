<?php

namespace App\Jobs;

use App\Mail\AlternativeTicketMail;
use App\Models\EmailLog;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\EmailJejak;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Kirim e-ticket ke alamat alternatif atas permintaan partner.
 *
 * orders.email TIDAK diubah: email pesanan tetap milik pembeli asli. Job ini
 * hanya mengirimkan salinan tiket ke alamat lain sekali, untuk kasus email
 * pembeli tidak sampai (mis. @outlook.com yang diblokir diam-diam).
 */
class SendAlternativeTicketEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $orderId,
        public string $tujuan,
        public ?int $userId = null,
    ) {
    }

    public function handle(OrderService $orders, EmailJejak $jejak): void
    {
        $order = Order::with($orders->relations())->find($this->orderId);

        if (! $order || ! $order->isPaid()) {
            return;
        }

        $jejak->kirim(
            new AlternativeTicketMail($order, $order->email),
            $order,
            $this->tujuan,
            EmailLog::JENIS_MANUAL,
            $this->userId,
        );
    }
}
