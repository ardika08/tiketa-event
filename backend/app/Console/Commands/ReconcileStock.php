<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\TicketType;
use App\Support\StockLedger;
use Illuminate\Console\Command;

class ReconcileStock extends Command
{
    protected $signature = 'nontix:reconcile-stock
        {--execute : Terapkan koreksi sisa_kuota}
        {--event= : Batasi ke satu event id}';

    protected $description = 'Hitung ulang sisa kuota tiket dari order (pending valid + lunas) dan koreksi bila menyimpang';

    public function handle(): int
    {
        $query = TicketType::query()->with('event:id,nama_event');

        if ($this->option('event')) {
            $query->where('event_id', (int) $this->option('event'));
        }

        $types = $query->get();
        $this->info("Memeriksa {$types->count()} jenis tiket...");

        $execute = (bool) $this->option('execute');
        $selisih = 0;

        // Order yang masih menahan kuota = pending sampai batas_bayar + tenggang,
        // sama dengan aturan pembatalan di OrderService::expireOverdue().
        $grace = max(0, (int) config('nontix.expiry_grace_minutes'));

        foreach ($types as $tt) {
            // Kuota terpakai = order lunas + order pending yang masih berlaku.
            $terpakaiLunas = $tt->orderItems()
                ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::PAID))
                ->sum('jumlah');

            $terpakaiPending = $tt->orderItems()
                ->whereHas('order', fn ($q) => $q
                    ->where('status', OrderStatus::PENDING)
                    ->where('batas_bayar', '>', now()->subMinutes($grace)))
                ->sum('jumlah');

            $seharusnya = max(0, $tt->kuota - $terpakaiLunas - $terpakaiPending);
            $beda = $seharusnya - $tt->sisa_kuota;

            if ($beda !== 0) {
                $selisih++;
                $label = "{$tt->event?->nama_event} / {$tt->nama_tiket}";
                $this->warn("  ! {$label}: sisa sekarang={$tt->sisa_kuota}, seharusnya={$seharusnya} (beda ".($beda > 0 ? '+' : '')."{$beda})");

                if ($execute) {
                    $lama = $tt->sisa_kuota;
                    $tt->update(['sisa_kuota' => $seharusnya]);
                    $tt->refresh();

                    StockLedger::record(
                        $tt,
                        $seharusnya - $lama,
                        StockLedger::RECONCILE,
                        "sekarang={$lama}, seharusnya={$seharusnya}",
                    );
                }
            }
        }

        if ($selisih === 0) {
            $this->info('Semua sisa kuota konsisten.');
        } elseif (! $execute) {
            $this->warn("");
            $this->warn("Ditemukan {$selisih} jenis tiket menyimpang. Jalankan ulang dengan --execute untuk koreksi.");
        } else {
            $this->info("Dikoreksi {$selisih} jenis tiket.");
        }

        return self::SUCCESS;
    }
}