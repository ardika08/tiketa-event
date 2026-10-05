<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\MayarService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPaidOrders extends Command
{
    protected $signature = 'nontix:audit-paid
        {--execute : Benar-benar kembalikan order yang ternyata belum dibayar}
        {--limit=200 : Jumlah maksimum order lunas yang diperiksa}';

    protected $description = 'Audit order berstatus lunas ke API Mayar; kembalikan ke pending bila invoice belum dibayar';

    public function handle(MayarService $mayar): int
    {
        if (! $mayar->isLive()) {
            $this->warn('Mayar tidak dalam mode live, audit dilewati.');

            return self::SUCCESS;
        }

        $execute = (bool) $this->option('execute');
        $limit = (int) $this->option('limit');

        $orders = Order::where('status', OrderStatus::PAID)
            ->whereHas('payments', fn ($q) => $q->whereNotNull('mayar_invoice_id'))
            ->with('payments', 'items')
            ->latest('paid_at')
            ->limit($limit)
            ->get();

        $this->info("Memeriksa {$orders->count()} order lunas...");

        $salah = 0;

        foreach ($orders as $order) {
            $payment = $order->payments->sortByDesc('id')->first();
            $invoiceId = $payment?->mayar_invoice_id;

            if (! $invoiceId || str_starts_with((string) $invoiceId, 'FAKE-')) {
                continue;
            }

            $invoice = $mayar->getInvoiceStatus($invoiceId);
            if (! $invoice) {
                $this->line("  ? {$order->kode_order}: status tidak bisa diambil");
                continue;
            }

            if (! $mayar->isPaidStatus(data_get($invoice, 'status'))) {
                $salah++;
                $status = data_get($invoice, 'status');
                $this->warn("  ! {$order->kode_order}: lunas di DB, tapi Mayar='{$status}'");

                if ($execute) {
                    DB::transaction(function () use ($order) {
                        foreach ($order->items as $item) {
                            $item->ticketType()->increment('sisa_kuota', $item->jumlah);
                        }

                        $order->tickets()->delete();
                        $order->update([
                            'status' => OrderStatus::PENDING,
                            'paid_at' => null,
                        ]);

                        $order->payments()->latest()->first()?->update([
                            'status' => 'pending',
                            'paid_at' => null,
                        ]);
                    });

                    $this->info("    -> {$order->kode_order} dikembalikan ke pending (kuota dipulihkan).");
                }
            } else {
                $this->line("  = {$order->kode_order}: valid lunas");
            }
        }

        if (! $execute && $salah > 0) {
            $this->warn("");
            $this->warn("Ditemukan {$salah} order bermasalah. Jalankan ulang dengan --execute untuk memperbaiki.");
        }

        return self::SUCCESS;
    }
}