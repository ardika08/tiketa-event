<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\PaymentManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPaidOrders extends Command
{
    protected $signature = 'nontix:audit-paid
        {--execute : Benar-benar kembalikan order yang ternyata belum dibayar}
        {--limit=200 : Jumlah maksimum order lunas yang diperiksa}';

    protected $description = 'Audit order berstatus lunas ke API gateway (Mayar/Xendit); kembalikan ke pending bila belum dibayar';

    public function handle(PaymentManager $payments): int
    {
        if (empty($payments->enabledGateways())) {
            $this->warn('Tidak ada gateway aktif, audit dilewati.');

            return self::SUCCESS;
        }

        $execute = (bool) $this->option('execute');
        $limit = (int) $this->option('limit');

        $orders = Order::where('status', OrderStatus::PAID)
            ->whereHas('payments')
            ->with('payments', 'items')
            ->latest('paid_at')
            ->limit($limit)
            ->get();

        $this->info("Memeriksa {$orders->count()} order lunas...");

        $salah = 0;

        foreach ($orders as $order) {
            $gateway = $payments->forOrder($order);

            if (! $gateway) {
                continue;
            }

            if ($gateway->confirmPaid($order)) {
                $this->line("  = {$order->kode_order}: valid lunas ({$gateway->name()})");

                continue;
            }

            $salah++;
            $this->warn("  ! {$order->kode_order}: lunas di DB, tapi gateway {$gateway->name()} menyatakan belum dibayar");

            if ($execute) {
                // Batas bayar baru; kuota TIDAK disentuh (sudah dipulihkan saat
                // dibatalkan/kadaluarsa). Order pending yang lewat batas
                // ditangani expire-orders yang idempoten.
                DB::transaction(function () use ($order) {
                    $order->tickets()->delete();
                    $order->update([
                        'status' => OrderStatus::PENDING,
                        'paid_at' => null,
                        'batas_bayar' => now()->addMinutes((int) config('nontix.order_expiry_minutes')),
                    ]);

                    $order->payments()->latest()->first()?->update([
                        'status' => 'pending',
                        'paid_at' => null,
                    ]);
                });

                $this->info("    -> {$order->kode_order} dikembalikan ke pending (batas bayar baru, kuota tidak disentuh).");
            }
        }

        if (! $execute && $salah > 0) {
            $this->warn('');
            $this->warn("Ditemukan {$salah} order bermasalah. Jalankan ulang dengan --execute untuk memperbaiki.");
        }

        return self::SUCCESS;
    }
}