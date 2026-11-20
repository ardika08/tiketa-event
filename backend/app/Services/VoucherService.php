<?php

namespace App\Services;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\Voucher;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    /**
     * Checkout locks the voucher until its quota reservation commits.
     */
    public function resolve(string $kode, Event $event, bool $lockForUpdate = false): Voucher
    {
        $kode = strtoupper(trim($kode));
        $voucher = Voucher::whereRaw('UPPER(kode) = ?', [$kode])->first();
        if ($voucher && $lockForUpdate) {
            // Lock by primary key so the case-insensitive lookup cannot lock unrelated vouchers.
            $voucher = Voucher::whereKey($voucher->id)->whereRaw('UPPER(kode) = ?', [$kode])
                ->lockForUpdate()->first();
        }

        if (! $voucher) {
            throw ValidationException::withMessages(['kode' => 'Kode voucher tidak ditemukan.']);
        }

        if (! $voucher->isUsable($event)) {
            throw ValidationException::withMessages(['kode' => 'Kode voucher sudah tidak berlaku.']);
        }

        return $voucher;
    }

    /** @param array<int, array{0: TicketType, 1: int}> $items */
    public function discountForItems(Voucher $voucher, array $items): float
    {
        // A non-null scope remains restricted even when its targets are deleted.
        $targets = $voucher->ticket_type_ids;
        $subtotal = 0.0;
        $matched = false;
        foreach ($items as [$ticket, $quantity]) {
            if ($targets === null || in_array((int) $ticket->id, $targets, true)) {
                $matched = true;
                $subtotal += (float) $ticket->harga * $quantity;
            }
        }

        if ($targets !== null && ! $matched) {
            throw ValidationException::withMessages(['kode' => 'Voucher ini tidak berlaku untuk tiket yang dipilih.']);
        }

        return $this->discountForSubtotal($voucher, $subtotal);
    }

    public function discountForLegacySubtotal(Voucher $voucher, float $subtotal): ?float
    {
        if ($voucher->ticket_type_ids !== null) {
            throw ValidationException::withMessages(['items' => 'Pilih tiket untuk memeriksa voucher ini.']);
        }

        return $subtotal > 0 ? $this->discountForSubtotal($voucher, $subtotal) : null;
    }

    private function discountForSubtotal(Voucher $voucher, float $subtotal): float
    {
        if (! $voucher->minPembelianTerpenuhi($subtotal)) {
            $minFormatted = 'Rp ' . number_format((float) $voucher->min_pembelian, 0, ',', '.');
            throw ValidationException::withMessages([
                'kode' => "Voucher ini berlaku untuk minimal pembelian {$minFormatted}.",
            ]);
        }

        return $voucher->hitungDiskon($subtotal);
    }
}
