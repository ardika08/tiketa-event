<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MayarService implements PaymentGateway
{
    public function name(): string
    {
        return 'mayar';
    }

    public function isEnabled(): bool
    {
        // Mode fake tetap "aktif" sebagai opsi demo; live butuh API key.
        return $this->isLive() || config('nontix.mayar.mode') === 'fake';
    }

    public function createPayment(Order $order): Payment
    {
        return $this->createInvoice($order);
    }

    public function confirmPaid(Order $order): bool
    {
        return $this->confirmInvoicePaid($order);
    }

    public function voidPayment(Order $order): bool
    {
        return $this->voidInvoice($order);
    }

    public function isFailedPayload(array $payload): bool
    {
        $event = $this->extractEvent($payload);
        if (str_contains($event, 'failed') || str_contains($event, 'cancel') || str_contains($event, 'expired')) {
            return true;
        }

        $status = data_get($payload, 'data.status') ?? data_get($payload, 'status');
        if (is_bool($status)) {
            return $status === false;
        }

        return in_array(strtolower((string) $status), ['failed', 'cancelled', 'canceled', 'expired', 'gagal'], true);
    }

    public function resolveOrder(array $payload): ?Order
    {
        $kode = $this->extractOrderCode($payload);
        if ($kode && ($order = Order::where('kode_order', $kode)->first())) {
            return $order;
        }

        $invoiceId = $this->extractInvoiceId($payload);
        if ($invoiceId) {
            $payment = Payment::where('mayar_invoice_id', $invoiceId)->latest()->first()
                ?? Payment::where('provider_reference', $invoiceId)->latest()->first();
            if ($payment) {
                return $payment->order;
            }
        }

        // Fallback: cocokkan email + nominal untuk order pending tanpa gateway jelas.
        $email = data_get($payload, 'data.customerEmail') ?? data_get($payload, 'customerEmail');
        $amount = data_get($payload, 'data.amount') ?? data_get($payload, 'amount');

        if ($email) {
            return Order::where('email', $email)
                ->where('status', 'pending')
                ->when($amount, fn ($q, $v) => $q->where('total_harga', $v))
                ->latest()
                ->first();
        }

        return null;
    }

    public function verifyWebhook(array $payload, ?string $token): bool
    {
        return $this->verifyCallback($payload, $token);
    }

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
                'provider' => $this->name(),
                'metode' => 'demo',
                'payment_url' => $this->fakePaymentUrl($order),
                'payload' => [
                    'mode' => 'fake',
                    'provider' => $this->name(),
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

        $url = data_get($invoice, 'link') ?? data_get($invoice, 'paymentUrl');

        $payment->update([
            'mayar_invoice_id' => data_get($invoice, 'id'),
            'provider' => $this->name(),
            'metode' => 'mayar',
            'payment_url' => $url,
            'payload' => [
                'mode' => 'live',
                'provider' => $this->name(),
                'invoice_id' => data_get($invoice, 'id'),
                'transaction_id' => data_get($invoice, 'transactionId'),
                'payment_url' => $url,
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
     * Konfirmasi ke API Mayar bahwa invoice benar-benar berstatus dibayar.
     * Dipakai webhook sebagai verifikasi tambahan, sehingga payload yang
     * menyesatkan (mis. payment.reminder) tidak menandai order lunas.
     */
    public function confirmInvoicePaid(Order $order): bool
    {
        if (! $this->isLive()) {
            return true;
        }

        $invoice = $this->currentInvoice($order);

        // Bila API tidak bisa dihubungi, jangan ubah status (aman).
        if ($invoice === null) {
            return false;
        }

        return $this->isPaidStatus(data_get($invoice, 'status'));
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
     * Ambil data invoice terkini milik sebuah order (null bila tidak ada).
     */
    private function currentInvoice(Order $order): ?array
    {
        $payment = $order->payments()->latest()->first();
        $invoiceId = $payment?->mayar_invoice_id;

        if (! $invoiceId || str_starts_with((string) $invoiceId, 'FAKE-')) {
            return null;
        }

        return $this->getInvoiceStatus($invoiceId);
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

    /**
     * Tentukan jenis event webhook dari payload (mis. payment.received).
     */
    public function extractEvent(array $payload): string
    {
        $event = data_get($payload, 'event')
            ?? data_get($payload, 'event.received')
            ?? data_get($payload, 'data.event')
            ?? data_get($payload, 'type')
            ?? '';

        if (is_array($event)) {
            $event = reset($event) ?: '';
        }

        return strtolower(trim((string) $event));
    }

    /**
     * Payload webhook hanya dicap "lunas" bila jenis event-nya benar-benar
     * pembayaran diterima (payment.received). Event lain (mis. payment.reminder)
     * TIDAK boleh menandai order lunas.
     */
    public function isPaidPayload(array $payload): bool
    {
        $event = $this->extractEvent($payload);

        if ($event !== '') {
            // Hanya payment.received yang menandakan pembayaran diterima.
            return str_contains($event, 'payment.received');
        }

        // Fallback (payload tanpa info event): status eksplisit saja.
        $status = data_get($payload, 'data.status') ?? data_get($payload, 'status');
        if (is_bool($status)) {
            return false; // Jangan percaya boolean tanpa konteks event.
        }

        return $this->isPaidStatus($status);
    }

    public function isPaidStatus(mixed $status): bool
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
