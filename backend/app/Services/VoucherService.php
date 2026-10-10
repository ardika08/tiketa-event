<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Voucher;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    /**
     * Cari & validasi voucher berdasarkan kode untuk sebuah event dan subtotal belanja.
     */
    public function resolve(string $kode, Event $event, float $subtotal = 0): Voucher
    {
        $voucher = Voucher::whereRaw('UPPER(kode) = ?', [strtoupper(trim($kode))])->first();

        if (! $voucher) {
            throw ValidationException::withMessages(['kode' => 'Kode voucher tidak ditemukan.']);
        }

        if (! $voucher->isUsable($event)) {
            throw ValidationException::withMessages(['kode' => 'Kode voucher sudah tidak berlaku.']);
        }

        if ($subtotal > 0 && ! $voucher->minPembelianTerpenuhi($subtotal)) {
            $minFormatted = 'Rp ' . number_format((float) $voucher->min_pembelian, 0, ',', '.');
            throw ValidationException::withMessages([
                'kode' => "Voucher ini berlaku untuk minimal pembelian {$minFormatted}.",
            ]);
        }

        return $voucher;
    }

    public function hitungDiskon(Voucher $voucher, float $subtotal): float
    {
        return $voucher->hitungDiskon($subtotal);
    }
}
