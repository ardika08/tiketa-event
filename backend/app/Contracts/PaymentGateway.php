<?php

namespace App\Contracts;

use App\Models\Order;
use App\Models\Payment;

interface PaymentGateway
{
    /**
     * Nama provider (mayar|xendit).
     */
    public function name(): string;

    /**
     * Apakah gateway aktif/terkonfigurasi untuk dipakai.
     */
    public function isEnabled(): bool;

    /**
     * Buat transaksi/invoice pembayaran untuk order ini.
     */
    public function createPayment(Order $order): Payment;

    /**
     * URL halaman pembayaran hosted yang bisa dibuka pembeli.
     */
    public function paymentUrl(Payment $payment): ?string;

    /**
     * Verifikasi bahwa order benar-benar sudah dibayar (tanya API gateway).
     */
    public function confirmPaid(Order $order): bool;

    /**
     * Batalkan/tutup transaksi (saat order kadaluarsa/dibatalkan).
     */
    public function voidPayment(Order $order): bool;

    /**
     * Cocokkan order dari payload webhook gateway ini.
     */
    public function resolveOrder(array $payload): ?Order;

    /**
     * Apakah payload webhook menandakan pembayaran diterima.
     */
    public function isPaidPayload(array $payload): bool;

    /**
     * Apakah payload webhook menandakan pembayaran gagal/dibatalkan.
     */
    public function isFailedPayload(array $payload): bool;

    /**
     * Verifikasi keaslian webhook.
     */
    public function verifyWebhook(array $payload, ?string $token): bool;
}