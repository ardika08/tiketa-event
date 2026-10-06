<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\SendOrderPaidEmail;
use App\Models\Order;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.mayar.callback_token', null);
    }

    private function scenario(): array
    {
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        $day1 = $event->sessions()->create(['label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100]);
        $day2 = $event->sessions()->create(['label' => 'Day 2', 'nama_session' => 'Puncak', 'urutan' => 2, 'kapasitas' => 100]);

        $single = $this->createTicketType($event, ['nama_tiket' => 'Harian', 'harga' => 100000, 'kuota' => 10, 'sisa_kuota' => 10], [$day1->id]);
        $bundle = $this->createTicketType($event, ['nama_tiket' => '2-Day Pass', 'harga' => 180000, 'kuota' => 5, 'sisa_kuota' => 5], [$day1->id, $day2->id]);

        return [$event, $organizer, $single, $bundle, $day1, $day2];
    }

    public function test_checkout_creates_pending_order_and_reserves_stock_without_tickets(): void
    {
        Queue::fake();
        [$event, , $single, $bundle] = $this->scenario();

        $res = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [
                ['ticket_type_id' => $bundle->id, 'jumlah' => 1],
                ['ticket_type_id' => $single->id, 'jumlah' => 2],
            ],
        ]);

        $res->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertNotNull($res->json('data.payment_url'));

        // Kuota direservasi selama pending.
        $this->assertSame(4, $bundle->fresh()->sisa_kuota);
        $this->assertSame(8, $single->fresh()->sisa_kuota);

        // Tiket + QR belum dibuat sebelum lunas.
        $order = Order::where('kode_order', $res->json('data.kode_order'))->firstOrFail();
        $this->assertCount(0, $order->tickets);
    }

    public function test_mark_paid_generates_tickets_with_per_session_passes(): void
    {
        Queue::fake();
        [$event, , $single, $bundle, $day1, $day2] = $this->scenario();

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [
                ['ticket_type_id' => $bundle->id, 'jumlah' => 1],
                ['ticket_type_id' => $single->id, 'jumlah' => 2],
            ],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $order = Order::where('kode_order', $kode)->firstOrFail();
        $this->assertCount(3, $order->tickets);

        $bundleTicket = $order->tickets->firstWhere('ticket_type_id', $bundle->id);
        $this->assertCount(2, $bundleTicket->passes);
        $this->assertEqualsCanonicalizing(
            [$day1->id, $day2->id],
            $bundleTicket->passes->pluck('event_session_id')->all(),
        );
    }

    public function test_mark_paid_is_idempotent_and_does_not_duplicate_tickets(): void
    {
        Queue::fake();
        [$event, , $single] = $this->scenario();

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $single->id, 'jumlah' => 2]],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();
        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $order = Order::where('kode_order', $kode)->firstOrFail();
        $this->assertCount(2, $order->tickets);
    }

    public function test_checkout_rejects_quantity_over_max_per_order(): void
    {
        [$event, , $single] = $this->scenario();

        $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $single->id, 'jumlah' => 99]],
        ])->assertStatus(422);
    }

    public function test_voucher_applies_discount(): void
    {
        Queue::fake();
        [$event, $organizer, $single] = $this->scenario();

        $organizer->vouchers()->create([
            'kode' => 'DISKON10', 'tipe_diskon' => 'persen', 'nilai' => 10,
            'kuota' => 10, 'status' => 'aktif', 'berlaku_sampai' => now()->addDay(),
        ]);

        $res = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'voucher_code' => 'diskon10',
            'items' => [['ticket_type_id' => $single->id, 'jumlah' => 2]],
        ]);

        $res->assertCreated();
        $this->assertSame(20000.0, (float) $res->json('data.diskon'));
        $this->assertSame(180000.0, (float) $res->json('data.total_harga'));
        $this->assertSame(1, Voucher::where('kode', 'DISKON10')->first()->terpakai);
    }

    public function test_fake_payment_marks_paid_and_dispatches_email(): void
    {
        Queue::fake();
        [$event, , $single] = $this->scenario();

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $single->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $order = Order::where('kode_order', $kode)->firstOrFail();
        $this->assertSame(OrderStatus::PAID, $order->status);
        Queue::assertPushed(SendOrderPaidEmail::class);
    }

    public function test_expired_order_is_cancelled_and_stock_restored(): void
    {
        Queue::fake();
        [$event, , $single] = $this->scenario();

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $single->id, 'jumlah' => 2]],
        ])->json('data.kode_order');

        $this->assertSame(8, $single->fresh()->sisa_kuota);

        // Lewati batas bayar + masa tenggang (default 5 menit).
        Order::where('kode_order', $kode)->update(['batas_bayar' => now()->subMinutes(6)]);

        $this->artisan('nontix:expire-orders')->assertSuccessful();

        $order = Order::where('kode_order', $kode)->firstOrFail();
        $this->assertSame(OrderStatus::EXPIRED, $order->status);
        $this->assertSame(10, $single->fresh()->sisa_kuota);
    }

    public function test_order_within_grace_period_is_not_cancelled_yet(): void
    {
        Queue::fake();
        [$event, , $single] = $this->scenario();

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $single->id, 'jumlah' => 2]],
        ])->json('data.kode_order');

        // Lewat batas bayar 1 menit, tapi masih di dalam masa tenggang:
        // pembayaran yang telat beberapa detik masih harus tertangkap.
        Order::where('kode_order', $kode)->update(['batas_bayar' => now()->subMinute()]);

        $this->artisan('nontix:expire-orders')->assertSuccessful();

        $order = Order::where('kode_order', $kode)->firstOrFail();
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertSame(8, $single->fresh()->sisa_kuota);
    }

    public function test_payment_arriving_during_grace_period_still_issues_ticket(): void
    {
        Queue::fake();
        [$event, , $single] = $this->scenario();

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $single->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        // Pembeli bayar mepet deadline: order sudah lewat batas bayar.
        Order::where('kode_order', $kode)->update(['batas_bayar' => now()->subMinutes(2)]);

        // Webhook telat masuk, tapi order belum dibatalkan (masih tenggang).
        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $order = Order::where('kode_order', $kode)->firstOrFail();
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertCount(1, $order->tickets);
    }
}
