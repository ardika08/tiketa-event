<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class XenditGatewayTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.mayar.api_key', null);
        config()->set('nontix.xendit.mode', 'test');
        config()->set('nontix.xendit.secret_key', 'xnd_development_test');
        config()->set('nontix.gateways', 'xendit');
    }

    private function payload($eventId, $ticketId, int $jumlah = 1): array
    {
        return [
            'event_id' => $eventId,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $ticketId, 'jumlah' => $jumlah]],
        ];
    }

    private function scenario(): array
    {
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, ['nama_tiket' => 'Reguler', 'harga' => 100000, 'kuota' => 10, 'sisa_kuota' => 10]);

        return [$event, $ticket];
    }

    public function test_create_session_sends_correct_payload_and_returns_payment_url(): void
    {
        Queue::fake();
        [$event, $ticket] = $this->scenario();

        Http::fake([
            'api.xendit.co/sessions' => Http::response([
                'payment_session_id' => 'ps-123',
                'reference_id' => 'NTX-TEST',
                'status' => 'ACTIVE',
                'payment_link_url' => 'https://xen.to/test123',
            ], 201),
        ]);

        $res = $this->postJson('/api/orders', $this->payload($event->id, $ticket->id));
        $res->assertCreated();

        $kode = $res->json('data.kode_order');
        $this->assertSame('xendit', $res->json('data.payment_provider'));
        $this->assertSame('https://xen.to/test123', $res->json('data.payment_url'));

        Http::assertSent(function ($request) use ($kode) {
            return str_ends_with($request->url(), '/sessions')
                && $request['reference_id'] === $kode
                && $request['session_type'] === 'PAY'
                && $request['mode'] === 'PAYMENT_LINK'
                && (int) $request['amount'] === 100000
                && $request['currency'] === 'IDR'
                && $request['country'] === 'ID';
        });

        $order = Order::where('kode_order', $kode)->firstOrFail();
        $this->assertSame('xendit', $order->gateway);
        $this->assertSame('ps-123', $order->payments()->latest()->first()->provider_reference);
    }

    public function test_webhook_completed_marks_paid_when_api_confirms(): void
    {
        Queue::fake();
        [$event, $ticket] = $this->scenario();

        Http::fake([
            'api.xendit.co/sessions' => Http::response([
                'payment_session_id' => 'ps-abc',
                'payment_link_url' => 'https://xen.to/abc',
            ], 201),
            'api.xendit.co/sessions/ps-abc' => Http::response([
                'payment_session_id' => 'ps-abc',
                'status' => 'COMPLETED',
                'payment_id' => 'py-123',
                'reference_id' => 'NTX',
            ], 200),
        ]);

        $kode = $this->postJson('/api/orders', $this->payload($event->id, $ticket->id))->json('data.kode_order');

        $this->postJson('/api/payments/callback', [
            'event' => 'payment_session.completed',
            'data' => [
                'payment_session_id' => 'ps-abc',
                'status' => 'COMPLETED',
                'reference_id' => $kode,
                'payment_id' => 'py-123',
            ],
        ])->assertOk();

        $order = Order::where('kode_order', $kode)->firstOrFail();
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertCount(1, $order->tickets);
    }

    public function test_webhook_expired_does_not_mark_paid(): void
    {
        Queue::fake();
        [$event, $ticket] = $this->scenario();

        Http::fake([
            'api.xendit.co/sessions' => Http::response([
                'payment_session_id' => 'ps-exp',
                'payment_link_url' => 'https://xen.to/exp',
            ], 201),
        ]);

        $kode = $this->postJson('/api/orders', $this->payload($event->id, $ticket->id))->json('data.kode_order');

        $this->postJson('/api/payments/callback', [
            'event' => 'payment_session.expired',
            'data' => ['payment_session_id' => 'ps-exp', 'reference_id' => $kode, 'status' => 'EXPIRED'],
        ])->assertOk();

        $this->assertSame(OrderStatus::PENDING, Order::where('kode_order', $kode)->first()->status);
    }

    public function test_webhook_token_is_verified_when_configured(): void
    {
        Queue::fake();
        config()->set('nontix.xendit.webhook_token', 'TOKENX');
        [$event, $ticket] = $this->scenario();

        Http::fake([
            'api.xendit.co/sessions' => Http::response([
                'payment_session_id' => 'ps-tok',
                'payment_link_url' => 'https://xen.to/tok',
            ], 201),
            'api.xendit.co/sessions/ps-tok' => Http::response([
                'payment_session_id' => 'ps-tok', 'status' => 'COMPLETED', 'payment_id' => 'py-1',
            ], 200),
        ]);

        $kode = $this->postJson('/api/orders', $this->payload($event->id, $ticket->id))->json('data.kode_order');
        $payload = ['event' => 'payment_session.completed', 'data' => ['payment_session_id' => 'ps-tok', 'reference_id' => $kode]];

        $this->postJson('/api/payments/callback', $payload)->assertStatus(401);
        $this->withHeaders(['x-callback-token' => 'TOKENX'])->postJson('/api/payments/callback', $payload)->assertOk();

        $this->assertSame(OrderStatus::PAID, Order::where('kode_order', $kode)->first()->status);
    }

    public function test_fallback_to_xendit_when_mayar_fails(): void
    {
        Queue::fake();
        config()->set('nontix.mayar.mode', 'live');
        config()->set('nontix.mayar.api_key', 'mayar-key');
        config()->set('nontix.gateways', 'mayar,xendit');

        [$event, $ticket] = $this->scenario();

        Http::fake([
            'api.mayar.id/hl/v1/invoice/create' => Http::response(['message' => 'error'], 500),
            'api.xendit.co/sessions' => Http::response([
                'payment_session_id' => 'ps-fb',
                'payment_link_url' => 'https://xen.to/fallback',
            ], 201),
        ]);

        $res = $this->postJson('/api/orders', $this->payload($event->id, $ticket->id));
        $res->assertCreated();

        $this->assertSame('xendit', $res->json('data.payment_provider'));
        $this->assertSame('https://xen.to/fallback', $res->json('data.payment_url'));
    }

    public function test_gateways_endpoint_lists_enabled_options(): void
    {
        $res = $this->getJson('/api/payments/gateways')->assertOk();
        $ids = collect($res->json('data'))->pluck('id');

        $this->assertTrue($ids->contains('xendit'));
    }
}