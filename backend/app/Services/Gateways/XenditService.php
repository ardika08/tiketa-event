<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integrasi Xendit Payment Session v3 (mode PAYMENT_LINK / hosted checkout).
 *
 * @see https://docs.xendit.co/apidocs/create-session
 * @see https://docs.xendit.co/apidocs/get-session
 * @see https://docs.xendit.co/apidocs/cancel-session
 */
class XenditService implements PaymentGateway
{
    public function name(): string
    {
        return 'xendit';
    }

    public function isEnabled(): bool
    {
        $secret = (string) config('nontix.xendit.secret_key');

        if ($secret === '') {
            return false;
        }

        // Public key tidak bisa dipakai untuk autentikasi server (Basic auth).
        if (str_contains($secret, 'xnd_public_')) {
            return false;
        }

        $mode = config('nontix.xendit.mode', 'test');

        // Mode live wajib kunci produksi (xnd_production_*), bukan development.
        if ($mode === 'live' && ! str_contains($secret, 'production')) {
            return false;
        }

        return true;
    }

    public function createPayment(Order $order): Payment
    {
        $payment = $order->payments()->create([
            'jumlah' => $order->total_harga,
            'status' => PaymentStatus::PENDING,
            'provider' => $this->name(),
        ]);

        if (! $this->isEnabled()) {
            $payment->update([
                'provider_reference' => 'FAKE-XND-'.$order->kode_order,
                'metode' => 'demo',
                'payload' => ['mode' => 'fake', 'provider' => $this->name(), 'payment_url' => $this->fakePaymentUrl($order)],
                'payment_url' => $this->fakePaymentUrl($order),
            ]);

            return $payment->fresh();
        }

        $payload = [
            'reference_id' => $order->kode_order,
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'amount' => (int) round((float) $order->total_harga),
            'currency' => 'IDR',
            'country' => 'ID',
            'description' => 'Order '.$order->kode_order.' - '.$order->event->nama_event,
            'customer' => array_filter([
                // WAJIB unik sepanjang masa di Xendit. Email pernah dipakai → 409
                // DUPLICATE_ERROR untuk SEMUA order berikutnya dari pembeli yang sama.
                // kode_order unik per order, jadi selalu aman.
                'reference_id' => $order->kode_order,
                'type' => 'INDIVIDUAL',
                'email' => $order->email,
                'mobile_number' => $this->formatPhone($order->whatsapp),
                'individual_detail' => array_filter([
                    'given_names' => $order->nama_pembeli,
                ]),
            ]),
            'items' => [[
                'reference_id' => $order->kode_order,
                'type' => 'DIGITAL_PRODUCT',
                'name' => 'Tiket '.$order->event->nama_event,
                'net_unit_amount' => (int) round((float) $order->total_harga),
                'quantity' => 1,
                'category' => 'Tiket',
            ]],
            'metadata' => [
                'kode_order' => $order->kode_order,
                'order_id' => (string) $order->id,
            ],
            'success_return_url' => $this->returnUrl($order, 'success'),
            'cancel_return_url' => $this->returnUrl($order, 'cancel'),
        ];

        if ($order->batas_bayar) {
            $payload['expires_at'] = $order->batas_bayar->clone()->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $response = Http::withBasicAuth((string) config('nontix.xendit.secret_key'), '')
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl().'/sessions', $payload);

        if ($response->failed()) {
            Log::error('[Xendit] create session gagal', [
                'order' => $order->kode_order,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $payment->update([
                'status' => PaymentStatus::FAILED,
                'payload' => ['provider' => $this->name(), 'error' => $response->json() ?? $response->body()],
            ]);

            return $payment->fresh();
        }

        $data = $response->json() ?? [];
        $url = data_get($data, 'payment_link_url');

        $payment->update([
            'provider_reference' => data_get($data, 'payment_session_id'),
            'metode' => 'xendit',
            'payment_url' => $url,
            'payload' => array_merge($data, ['provider' => $this->name(), 'payment_url' => $url]),
        ]);

        return $payment->fresh();
    }

    public function paymentUrl(Payment $payment): ?string
    {
        return $payment->payment_url
            ?? data_get($payment->payload, 'payment_link_url')
            ?? data_get($payment->payload, 'payment_url');
    }

    public function confirmPaid(Order $order): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        $sessionId = $this->sessionId($order);
        if (! $sessionId) {
            return false;
        }

        $response = Http::withBasicAuth((string) config('nontix.xendit.secret_key'), '')
            ->acceptJson()
            ->get($this->baseUrl().'/sessions/'.$sessionId);

        if ($response->failed()) {
            Log::warning('[Xendit] get session gagal', ['session' => $sessionId, 'status' => $response->status()]);

            return false;
        }

        $data = $response->json() ?? [];
        $status = strtoupper((string) data_get($data, 'status'));

        return in_array($status, ['COMPLETED', 'PAID', 'SETTLED'], true)
            && data_get($data, 'payment_id') !== null;
    }

    public function voidPayment(Order $order): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $sessionId = $this->sessionId($order);
        if (! $sessionId) {
            return false;
        }

        $response = Http::withBasicAuth((string) config('nontix.xendit.secret_key'), '')
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl().'/sessions/'.$sessionId.'/cancel');

        if ($response->failed()) {
            Log::warning('[Xendit] cancel session gagal', ['session' => $sessionId, 'status' => $response->status()]);

            return false;
        }

        return true;
    }

    public function resolveOrder(array $payload): ?Order
    {
        $kode = data_get($payload, 'data.reference_id')
            ?? data_get($payload, 'reference_id')
            ?? data_get($payload, 'data.metadata.kode_order')
            ?? data_get($payload, 'metadata.kode_order');

        if ($kode && ($order = Order::where('kode_order', $kode)->first())) {
            return $order;
        }

        $sessionId = data_get($payload, 'data.payment_session_id')
            ?? data_get($payload, 'payment_session_id')
            ?? data_get($payload, 'data.id');

        if ($sessionId) {
            $payment = Payment::where('provider_reference', $sessionId)->latest()->first();
            if ($payment) {
                return $payment->order;
            }
        }

        return null;
    }

    public function isPaidPayload(array $payload): bool
    {
        $event = $this->extractEvent($payload);

        if (in_array($event, ['payment_session.completed', 'payment.capture', 'payment.authorization', 'payment.succeeded'], true)) {
            return true;
        }

        if ($event !== '' && (str_contains($event, 'expired') || str_contains($event, 'failure') || str_contains($event, 'failed'))) {
            return false;
        }

        $status = strtoupper((string) (data_get($payload, 'data.status') ?? data_get($payload, 'status') ?? ''));

        return in_array($status, ['COMPLETED', 'PAID', 'SETTLED', 'SUCCEEDED', 'CAPTURED', 'AUTHORIZED'], true);
    }

    public function isFailedPayload(array $payload): bool
    {
        $event = $this->extractEvent($payload);

        if (in_array($event, ['payment_session.expired', 'payment.failure', 'payment.failed'], true)) {
            return true;
        }

        $status = strtoupper((string) (data_get($payload, 'data.status') ?? data_get($payload, 'status') ?? ''));

        return in_array($status, ['EXPIRED', 'FAILED', 'CANCELED', 'CANCELLED'], true);
    }

    public function verifyWebhook(array $payload, ?string $token): bool
    {
        $expected = config('nontix.xendit.webhook_token');

        if (! $expected) {
            return true;
        }

        return hash_equals((string) $expected, (string) $token);
    }

    private function extractEvent(array $payload): string
    {
        $event = data_get($payload, 'event') ?? data_get($payload, 'type') ?? '';

        if (is_array($event)) {
            $event = reset($event) ?: '';
        }

        return strtolower(trim((string) $event));
    }

    private function sessionId(Order $order): ?string
    {
        $payment = $order->payments()->where('provider', $this->name())->latest()->first()
            ?? $order->payments()->latest()->first();

        $id = $payment?->provider_reference ?? $payment?->mayar_invoice_id;

        if (! $id || str_starts_with((string) $id, 'FAKE-')) {
            return null;
        }

        return $id;
    }

    private function returnUrl(Order $order, string $kind): string
    {
        $base = rtrim((string) config('nontix.frontend_url'), '/');

        return $kind === 'success'
            ? $base.'/pembayaran/berhasil?order='.$order->kode_order
            : $base.'/pembayaran?order='.$order->kode_order;
    }

    private function formatPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $clean = preg_replace('/[^0-9]/', '', $phone);
        if ($clean === '') {
            return null;
        }

        if (str_starts_with($clean, '0')) {
            return '+62'.substr($clean, 1);
        }
        if (str_starts_with($clean, '62')) {
            return '+'.$clean;
        }

        return '+'.$clean;
    }

    private function fakePaymentUrl(Order $order): string
    {
        return rtrim((string) config('app.url'), '/').'/api/payments/'.$order->kode_order.'/fake';
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('nontix.xendit.base_url', 'https://api.xendit.co'), '/');
    }
}