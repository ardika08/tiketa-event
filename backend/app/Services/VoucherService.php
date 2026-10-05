<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Voucher;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    /**
     * Cari & validasi voucher berdasarkan kode untuk sebuah event.
     */
    public function resolve(string $kode, Event $event): Voucher
    {
        $voucher = Voucher::whereRaw('UPPER(kode) = ?', [strtoupper(trim($kode))])->first();

        if (! $voucher) {
            throw ValidationException::withMessages(['kode' => 'Kode voucher tidak ditemukan.']);
        }

        if (! $voucher->isUsable($event->id)) {
            throw ValidationException::withMessages(['kode' => 'Kode voucher sudah tidak berlaku.']);
        }

        return $voucher;
    }

    public function hitungDiskon(Voucher $voucher, float $subtotal): float
    {
        return $voucher->hitungDiskon($subtotal);
    }
}
