<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentManager;
use Illuminate\Console\Command;

class SyncPayments extends Command
{
    protected $signature = 'nontix:sync-payments';

    protected $description = 'Rekonsiliasi status pembayaran (Mayar/Xendit) untuk order pending';

    public function handle(OrderService $orders, PaymentManager $payments): int
    {
        if (empty($payments->enabledGateways())) {
            $this->info('Tidak ada gateway aktif, sync dilewati.');

            return self::SUCCESS;
        }

        $count = 0;

        Order::query()
            ->where('status', OrderStatus::PENDING)
            ->whereNotNull('batas_bayar')
            ->where('batas_bayar', '>', now())
            ->with('payments')
            ->chunkById(50, function ($list) use ($orders, $payments, &$count) {
                foreach ($list as $order) {
                    $gateway = $payments->forOrder($order);

                    if ($gateway && $gateway->confirmPaid($order)) {
                        $orders->markPaid($order, ['mode' => 'sync', 'status' => 'paid'], $gateway->name());
                        $count++;
                    }
                }
            });

        $this->info("Sync selesai. {$count} order ditandai lunas.");

        return self::SUCCESS;
    }
}