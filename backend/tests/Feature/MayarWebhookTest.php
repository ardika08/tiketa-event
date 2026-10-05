<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class MayarWebhookTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pastikan verifikasi token webhook tidak aktif pada test ini.
        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.mayar.callback_token', null);
    }

    private function createOrder(): Order
    {
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $ticket = $this->createTicketType($event, ['nama_tiket' => 'Reguler', 'harga' => 100000, 'kuota' => 10, 'sisa_kuota' => 10]);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $ticket->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        return Order::where('kode_order', $kode)->firstOrFail();
    }

    public function test_webhook_payment_received_marks_order_paid_by_order_code(): void
    {
        Queue::fake();
        $order = $this->createOrder();

        $this->postJson('/api/payments/callback', [
            'event' => 'payment.received',
            'data' => [
                'status' => true,
                'customerEmail' => $order->email,
                'amount' => (int) $order->total_harga,
                'extraData' => ['noCustomer' => $order->kode_order],
            ],
        ])->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
    }

    public function test_webhook_can_resolve_order_by_invoice_id(): void
    {
        Queue::fake();
        $order = $this->createOrder();
        $invoiceId = $order->payments()->latest()->first()->mayar_invoice_id;

        $this->postJson('/api/payments/callback', [
            'event' => 'payment.received',
            'data' => ['status' => true, 'paymentLinkId' => $invoiceId],
        ])->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
    }

    public function test_webhook_falls_back_to_email_and_amount(): void
    {
        Queue::fake();
        $order = $this->createOrder();

        $this->postJson('/api/payments/callback', [
            'event' => 'payment.received',
            'data' => [
                'status' => true,
                'customerEmail' => $order->email,
                'amount' => (int) $order->total_harga,
            ],
        ])->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
    }
}
