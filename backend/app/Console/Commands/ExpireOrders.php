<?php

namespace App\Console\Commands;

use App\Services\OrderService;
use Illuminate\Console\Command;

class ExpireOrders extends Command
{
    protected $signature = 'nontix:expire-orders';

    protected $description = 'Batalkan order pending yang melewati batas waktu pembayaran';

    public function handle(OrderService $orders): int
    {
        $count = $orders->expireOverdue();
        $this->info("Berhasil membatalkan {$count} order kadaluarsa.");

        return self::SUCCESS;
    }
}
