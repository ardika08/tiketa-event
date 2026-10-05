<?php

namespace App\Jobs;

use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Services\MailketingService;
use App\Services\OrderService;
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

    public function handle(OrderService $orders, MailketingService $mail): void
    {
        $order = Order::with($orders->relations())->find($this->orderId);

        if (! $order || ! $order->isPaid()) {
            return;
        }

        $mail->send(new OrderPaidMail($order), $order->email);
    }
}
