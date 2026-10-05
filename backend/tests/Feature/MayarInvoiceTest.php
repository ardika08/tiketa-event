<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class MayarInvoiceTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nontix.mayar.mode', 'live');
        config()->set('nontix.mayar.api_key', 'test-key');
        config()->set('nontix.mayar.base_url', 'https://api.mayar.id/hl/v1');
        config()->set('nontix.mayar.callback_token', null);
    }

    private function orderPayload($eventId, $ticketId): array
    {
        return [
            'event_id' => $eventId,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $ticketId, 'jumlah' => 1]],
        ];
    }

    public function test_create_invoice_sends_correct_payload(): void
    {
        Queue::fake();
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, ['harga' => 100000]);

        Http::fake([
            'api.mayar.id/*' => Http::response([
                'statusCode' => 200,
                'messages' => 'success',
                'data' => [
                    'id' => 'inv-123',
                    'transactionId' => 'trx-123',
                    'link' => 'https://contoh.mayar.shop/invoices/abc',
                    'expiredAt' => 1776617003000,
                ],
            ], 200),
        ]);

        $res = $this->postJson('/api/orders', $this->orderPayload($event->id, $ticket->id));
        $res->assertCreated();

        $kode = $res->json('data.kode_order');
        $this->assertSame('mayar', $res->json('data.payment_provider'));
        $this->assertSame('https://contoh.mayar.shop/invoices/abc', $res->json('data.payment_url'));

        Http::assertSent(function ($request) use ($kode) {
            return str_contains($request->url(), '/invoice/create')
                && $request['name'] === 'Budi'
                && $request['email'] === 'budi@mail.com'
                && $request['mobile'] === '08123456789'
                && data_get($request['extraData'], 'noCustomer') === $kode
                && $request['items'][0]['quantity'] === 1
                && $request['items'][0]['rate'] === 100000
                && str_contains($request['items'][0]['description'], $kode);
        });

        $order = Order::where('kode_order', $kode)->firstOrFail();
        $this->assertSame('inv-123', $order->payments()->latest()->first()->mayar_invoice_id);
    }

    public function test_sync_marks_order_paid_when_invoice_is_paid(): void
    {
        Queue::fake();
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, ['harga' => 100000]);

        Http::fake([
            'api.mayar.id/hl/v1/invoice/create' => Http::response([
                'data' => ['id' => 'inv-999', 'link' => 'https://contoh.mayar.shop/invoices/xyz'],
            ], 200),
            'api.mayar.id/hl/v1/invoice/inv-999' => Http::response([
                'data' => ['id' => 'inv-999', 'status' => 'paid', 'amount' => 100000],
            ], 200),
        ]);

        $kode = $this->postJson('/api/orders', $this->orderPayload($event->id, $ticket->id))->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'lunas');

        $this->assertSame(OrderStatus::PAID, Order::where('kode_order', $kode)->first()->status);
    }

    public function test_webhook_requires_token_when_configured(): void
    {
        Queue::fake();
        config()->set('nontix.mayar.callback_token', 'RAHASIA');

        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, ['harga' => 100000]);

        Http::fake([
            'api.mayar.id/hl/v1/invoice/create' => Http::response([
                'data' => ['id' => 'inv-tok', 'link' => 'https://contoh.mayar.shop/invoices/tok'],
            ], 200),
        ]);

        $kode = $this->postJson('/api/orders', $this->orderPayload($event->id, $ticket->id))->json('data.kode_order');

        $payload = [
            'event' => 'payment.received',
            'data' => ['status' => true, 'extraData' => ['noCustomer' => $kode]],
        ];

        $this->postJson('/api/payments/callback', $payload)->assertStatus(401);
        $this->postJson('/api/payments/callback?token=RAHASIA', $payload)->assertOk();

        $this->assertSame(OrderStatus::PAID, Order::where('kode_order', $kode)->first()->status);
    }

    public function test_expired_order_voids_mayar_invoice(): void
    {
        Queue::fake();

        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, ['harga' => 100000]);

        Http::fake([
            'api.mayar.id/hl/v1/invoice/create' => Http::response([
                'data' => ['id' => 'inv-77', 'link' => 'https://contoh.mayar.shop/invoices/77'],
            ], 200),
            'api.mayar.id/hl/v1/invoice/edit' => Http::response(['statusCode' => 200, 'messages' => 'success'], 200),
        ]);

        $kode = $this->postJson('/api/orders', $this->orderPayload($event->id, $ticket->id))->json('data.kode_order');

        Order::where('kode_order', $kode)->update(['batas_bayar' => now()->subMinute()]);

        $this->artisan('nontix:expire-orders')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/invoice/edit') && $request['id'] === 'inv-77');
        $this->assertSame(OrderStatus::EXPIRED, Order::where('kode_order', $kode)->first()->status);
    }

    public function test_admin_can_register_mayar_webhook(): void
    {
        Http::fake([
            'api.mayar.id/hl/v2/webhooks/update' => Http::response(['statusCode' => 200, 'messages' => 'success'], 200),
        ]);

        Sanctum::actingAs($this->createAdmin());

        $this->postJson('/api/admin/mayar/webhook')
            ->assertOk()
            ->assertJsonPath('mayar.messages', 'success');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/hl/v2/webhooks/update') && ! empty($request['urlHook']));
    }
}
