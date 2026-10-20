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

    public function __construct(public int $orderId)
    {
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
            EmailLog::JENIS_INVOICE,
        );
    }
}
