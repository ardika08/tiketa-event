<?php

namespace App\Jobs;

use App\Mail\OrderPaidMail;
use App\Models\EmailLog;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\EmailJejak;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOrderPaidEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * $jenis membedakan email invoice dari gateway vs dari penjualan manual
     * partner, supaya riwayat pengiriman jujur soal asal pesanannya.
     */
    public function __construct(
        public int $orderId,
        public string $jenis = EmailLog::JENIS_INVOICE,
    ) {
    }

    public function handle(OrderService $orders, EmailJejak $jejak): void
    {
        $order = Order::with($orders->relations())->find($this->orderId);

        if (! $order || ! $order->isPaid()) {
            return;
        }

        $jejak->kirim(
            new OrderPaidMail($order),
            $order,
            $order->email,
            $this->jenis,
        );
    }
}
