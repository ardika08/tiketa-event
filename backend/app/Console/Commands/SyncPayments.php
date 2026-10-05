<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\MayarService;
use App\Services\OrderService;
use Illuminate\Console\Command;

class SyncPayments extends Command
{
    protected $signature = 'nontix:sync-payments';

    protected $description = 'Rekonsiliasi status pembayaran Mayar untuk order pending';

    public function handle(OrderService $orders, MayarService $mayar): int
    {
        if (! $mayar->isLive()) {
            $this->info('Mayar tidak dalam mode live, sync dilewati.');

            return self::SUCCESS;
        }

        $count = 0;

        Order::query()
            ->where('status', OrderStatus::PENDING)
            ->whereNotNull('batas_bayar')
            ->where('batas_bayar', '>', now())
            ->with('payments')
            ->chunkById(50, function ($list) use ($orders, $mayar, &$count) {
                foreach ($list as $order) {
                    if ($mayar->syncPayment($order, $orders)) {
                        $count++;
                    }
                }
            });

        $this->info("Sync selesai. {$count} order ditandai lunas.");

        return self::SUCCESS;
    }
}
