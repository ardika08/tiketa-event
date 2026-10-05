<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Gateways\XenditService;

/**
 * Mengatur pemilihan & fallback payment gateway.
 *
 * Urutan percobaan diambil dari config('nontix.gateways') (mis. mayar,xendit).
 * Bila gateway pertama tidak aktif atau gagal membuat transaksi, otomatis
 * mencoba gateway berikutnya.
 */
class PaymentManager
{
    public function __construct(
        private MayarService $mayar,
        private XenditService $xendit,
    ) {
    }

    /**
     * @return array<int, PaymentGateway>
     */
    public function gateways(): array
    {
        $map = [
            'mayar' => $this->mayar,
            'xendit' => $this->xendit,
        ];

        $order = array_filter(array_map('trim', explode(',', (string) config('nontix.gateways', 'mayar,xendit'))));

        return array_values(array_filter(array_map(fn ($name) => $map[$name] ?? null, $order)));
    }

    public function enabledGateways(): array
    {
        return array_values(array_filter($this->gateways(), fn (PaymentGateway $g) => $g->isEnabled()));
    }

    /**
     * Gateway yang tersedia untuk ditawarkan ke pembeli.
     *
     * @return array<int, array{id:string,label:string}>
     */
    public function options(): array
    {
        $labels = ['mayar' => 'Mayar', 'xendit' => 'Xendit'];

        return array_map(
            fn (PaymentGateway $g) => ['id' => $g->name(), 'label' => $labels[$g->name()] ?? ucfirst($g->name())],
            $this->enabledGateways(),
        );
    }

    /**
     * Buat pembayaran untuk order memakai gateway tertentu, atau mencoba
     * gateway berurutan bila $preferred kosong/tidak tersedia.
     * Mengembalikan [Payment, gateway name] atau null bila semuanya gagal.
     *
     * @return array{0: Payment, 1: string}|null
     */
    public function createFor(Order $order, ?string $preferred = null): ?array
    {
        $enabled = $this->enabledGateways();

        // Gateway pilihan pembeli dicoba lebih dulu, lalu sisanya sebagai fallback.
        $ordered = $enabled;
        if ($preferred) {
            $preferredGateway = array_values(array_filter(
                $enabled,
                fn (PaymentGateway $g) => $g->name() === $preferred,
            ));
            $others = array_values(array_filter(
                $enabled,
                fn (PaymentGateway $g) => $g->name() !== $preferred,
            ));
            $ordered = array_merge($preferredGateway, $others);
        }

        foreach ($ordered as $gateway) {
            $payment = $gateway->createPayment($order);

            if ($payment->status !== \App\Enums\PaymentStatus::FAILED) {
                $order->update(['gateway' => $gateway->name()]);

                return [$payment, $gateway->name()];
            }
        }

        return null;
    }

    /**
     * Cari gateway dari nama (untuk webhook/void/confirm).
     */
    public function gateway(string $name): ?PaymentGateway
    {
        foreach ($this->gateways() as $gateway) {
            if ($gateway->name() === $name) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * Gateway default untuk order (dipakai saat void/confirm bila tidak jelas).
     */
    public function forOrder(Order $order): ?PaymentGateway
    {
        $name = $order->gateway;

        if ($name && ($gateway = $this->gateway($name))) {
            return $gateway;
        }

        $payment = $order->payments()->latest()->first();
        if ($payment?->provider && ($gateway = $this->gateway($payment->provider))) {
            return $gateway;
        }

        return $this->enabledGateways()[0] ?? null;
    }
}