<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MayarService
{
    public function isLive(): bool
    {
        return config('nontix.mayar.mode') === 'live' && ! empty(config('nontix.mayar.api_key'));
    }

    public function provider(): string
    {
        return $this->isLive() ? 'mayar' : 'fake';
    }

    /**
     * Buat invoice pembayaran via Mayar (atau simulasi bila mode fake).
     *
     * @see https://docs.mayar.id/api-reference/invoice/create
     */
    public function createInvoice(Order $order): Payment
    {
        $payment = $order->payments()->create([
            'jumlah' => $order->total_harga,
            'status' => PaymentStatus::PENDING,
        ]);

        if (! $this->isLive()) {
            $payment->update([
                'mayar_invoice_id' => 'FAKE-'.$order->kode_order,
                'metode' => 'demo',
                'payload' => [
                    'mode' => 'fake',
                    'payment_url' => $this->fakePaymentUrl($order),
                ],
            ]);

            return $payment->fresh();
        }

        // Satu item konsolidasi agar nominal invoice = total akhir (setelah diskon).
        $rincian = $order->items
            ->map(fn ($item) => ($item->ticketType?->nama_tiket ?? 'Tiket').' x'.$item->jumlah)
            ->implode(', ');

        $items = [[
            'quantity' => 1,
            'rate' => (int) round((float) $order->total_harga),
            'description' => 'Order '.$order->kode_order.' — '.$rincian,
        ]];

        $response = Http::withToken(config('nontix.mayar.api_key'))
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl().'/invoice/create', [
                'name' => $order->nama_pembeli,
                'email' => $order->email,
                'mobile' => $order->whatsapp,
                'redirectUrl' => $this->redirectUrl($order),
                'description' => 'Order '.$order->kode_order.' - '.$order->event->nama_event,
                'expiredAt' => $this->expiredAt($order),
                'items' => $items,
                'extraData' => [
                    'noCustomer' => $order->kode_order,
                    'idProd' => 'nontix',
                    'orderId' => (string) $order->id,
                ],
            ]);

        if ($response->failed()) {
            Log::error('[Mayar] create invoice gagal', [
                'order' => $order->kode_order,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $payment->update([
                'status' => PaymentStatus::FAILED,
                'payload' => ['mode' => 'live', 'error' => $response->json() ?? $response->body()],
            ]);

            return $payment->fresh();
        }

        $data = $response->json() ?? [];
        $invoice = data_get($data, 'data', $data);

        $payment->update([
            'mayar_invoice_id' => data_get($invoice, 'id'),
            'metode' => 'mayar',
            'payload' => [
                'mode' => 'live',
                'invoice_id' => data_get($invoice, 'id'),
                'transaction_id' => data_get($invoice, 'transactionId'),
                'payment_url' => data_get($invoice, 'link') ?? data_get($invoice, 'paymentUrl'),
                'expired_at' => data_get($invoice, 'expiredAt'),
                'raw' => $data,
            ],
        ]);

        return $payment->fresh();
    }

    public function paymentUrl(Payment $payment): ?string
    {
        return data_get($payment->payload, 'payment_url') ?? data_get($payment->payload, 'link');
    }

    /**
     * Ambil status invoice langsung dari Mayar.
     *
     * @see https://docs.mayar.id/api-reference/invoice/detail
     */
    public function getInvoiceStatus(string $invoiceId): ?array
    {
        if (! $this->isLive()) {
            return null;
        }

        $response = Http::withToken(config('nontix.mayar.api_key'))
            ->acceptJson()
            ->get($this->baseUrl().'/invoice/'.$invoiceId);

        if ($response->failed()) {
            Log::warning('[Mayar] get invoice gagal', ['invoice' => $invoiceId, 'status' => $response->status()]);

            return null;
        }

        return data_get($response->json(), 'data', $response->json());
    }

    /**
     * Cek status invoice dan tandai order lunas bila sudah dibayar.
     * Berguna sebagai rekonsiliasi bila webhook tidak sampai.
     */
    public function syncPayment(Order $order, OrderService $orders): bool
    {
        if (! $this->isLive() || ! $order->isPending()) {
            return false;
        }

        $payment = $order->payments()->latest()->first();
        $invoiceId = $payment?->mayar_invoice_id;

        if (! $invoiceId || str_starts_with((string) $invoiceId, 'FAKE-')) {
            return false;
        }

        $invoice = $this->getInvoiceStatus($invoiceId);
        if (! $invoice) {
            return false;
        }

        if ($this->isPaidStatus(data_get($invoice, 'status'))) {
            $orders->markPaid($order, $invoice, 'mayar');

            return true;
        }

        return false;
    }

    /**
     * Batalkan invoice Mayar saat order dibatalkan/kadaluarsa.
     * Mayar tidak punya endpoint "close", jadi invoice dibuat kedaluwarsa
     * (expiredAt = sekarang) via endpoint edit.
     *
     * @see https://docs.mayar.id/api-reference/invoice/edit
     */
    public function voidInvoice(Order $order): bool
    {
        if (! $this->isLive()) {
            return false;
        }

        $payment = $order->payments()->latest()->first();
        $invoiceId = $payment?->mayar_invoice_id;

        if (! $invoiceId || str_starts_with((string) $invoiceId, 'FAKE-')) {
            return false;
        }

        $response = Http::withToken(config('nontix.mayar.api_key'))
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl().'/invoice/edit', [
                'id' => $invoiceId,
                'name' => $order->nama_pembeli,
                'email' => $order->email,
                'mobile' => $order->whatsapp,
                'redirectUrl' => $this->redirectUrl($order),
                'description' => 'Order '.$order->kode_order.' dibatalkan',
                'expiredAt' => now()->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'items' => [[
                    'quantity' => 1,
                    'rate' => (int) round((float) $order->total_harga),
                    'description' => 'Order '.$order->kode_order.' dibatalkan',
                ]],
            ]);

        if ($response->failed()) {
            Log::warning('[Mayar] void invoice gagal', [
                'order' => $order->kode_order,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Daftarkan URL webhook ke Mayar (API v2).
     *
     * @see https://docs.mayar.id/api-reference-v2/webhook/registerurlhook
     */
    public function registerWebhook(string $url): array
    {
        if (! $this->isLive()) {
            return ['statusCode' => 0, 'messages' => 'Mode fake: webhook tidak didaftarkan ke Mayar.'];
        }

        $response = Http::withToken(config('nontix.mayar.api_key'))
            ->acceptJson()
            ->asJson()
            ->post($this->v2BaseUrl().'/webhooks/update', ['urlHook' => $url]);

        return $response->json() ?? ['statusCode' => $response->status(), 'messages' => $response->body()];
    }

    public function webhookUrl(): string
    {
        $url = rtrim((string) config('app.url'), '/').'/api/payments/callback';
        $token = config('nontix.mayar.callback_token');

        return $token ? $url.'?token='.$token : $url;
    }

    public function verifyCallback(array $payload, ?string $token): bool
    {
        $expected = config('nontix.mayar.callback_token');

        if ($this->isLive() && $expected) {
            return hash_equals((string) $expected, (string) $token);
        }

        return true;
    }

    public function extractOrderCode(array $payload): ?string
    {
        return data_get($payload, 'extraData.noCustomer')
            ?? data_get($payload, 'data.extraData.noCustomer')
            ?? data_get($payload, 'reference_id')
            ?? data_get($payload, 'data.reference_id')
            ?? data_get($payload, 'kode_order')
            ?? data_get($payload, 'data.kode_order');
    }

    public function extractInvoiceId(array $payload): ?string
    {
        return data_get($payload, 'data.paymentLinkId')
            ?? data_get($payload, 'data.invoiceId')
            ?? data_get($payload, 'data.id')
            ?? data_get($payload, 'paymentLinkId')
            ?? data_get($payload, 'invoiceId')
            ?? data_get($payload, 'id');
    }

    public function isPaidPayload(array $payload): bool
    {
        $event = strtolower((string) (data_get($payload, 'event') ?? data_get($payload, 'event.received') ?? ''));
        if ($event === 'payment.received') {
            return true;
        }

        $status = data_get($payload, 'data.status') ?? data_get($payload, 'status');
        if (is_bool($status)) {
            return $status;
        }

        return $this->isPaidStatus($status);
    }

    private function isPaidStatus(mixed $status): bool
    {
        if (is_bool($status)) {
            return $status;
        }

        return in_array(strtolower((string) $status), ['paid', 'settled', 'success', 'berhasil', 'lunas'], true);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('nontix.mayar.base_url'), '/');
    }

    private function v2BaseUrl(): string
    {
        return str_replace('/hl/v1', '/hl/v2', $this->baseUrl());
    }

    private function redirectUrl(Order $order): string
    {
        return rtrim((string) config('nontix.frontend_url'), '/').'/pembayaran/berhasil?order='.$order->kode_order;
    }

    private function expiredAt(Order $order): string
    {
        $batas = $order->batas_bayar ?? now()->addMinutes((int) config('nontix.order_expiry_minutes'));

        return $batas->clone()->utc()->format('Y-m-d\TH:i:s.000\Z');
    }

    private function fakePaymentUrl(Order $order): string
    {
        return rtrim((string) config('app.url'), '/').'/api/payments/'.$order->kode_order.'/fake';
    }
}
