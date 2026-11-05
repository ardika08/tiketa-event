<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Order dengan total Rp 0 (tiket gratis atau voucher menutup penuh) HARUS
 * langsung lunas tanpa gateway: tiket + QR terbit saat itu juga, tanpa
 * pembeli menekan tombol bayar.
 *
 * Backend path: OrderController::store → total <= 0 → markPaid().
 */
class OrderGratisTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    private function scenario(): array
    {
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        $gratis = $this->createTicketType($event, [
            'nama_tiket' => 'Bebas Biaya',
            'harga' => 0,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 2,
        ]);

        $bayar = $this->createTicketType($event, [
            'nama_tiket' => 'VIP',
            'harga' => 100000,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 2,
        ]);

        return [$event, $gratis, $bayar];
    }

    public function test_order_tiket_gratis_langsung_lunas_tanpa_gateway(): void
    {
        Queue::fake();
        [$event, $gratis] = $this->scenario();

        $res = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Tamu',
            'email' => 'tamu@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $gratis->id, 'jumlah' => 1]],
        ]);

        $res->assertCreated();
        // Langsung lunas, bukan pending.
        $this->assertSame('lunas', $res->json('data.status'));

        // Tidak ada payment_url karena tidak ada gateway yang terlibat.
        $this->assertNull($res->json('data.payment_url'));

        $order = Order::where('kode_order', $res->json('data.kode_order'))->firstOrFail();
        $this->assertSame('lunas', $order->status->value);
        $this->assertNotNull($order->paid_at);

        // Tiket + QR sudah terbuat, bisa dipakai scan di gate.
        // QR hidup di TicketPass.kode_qr (bukan kolom di Ticket).
        $this->assertCount(1, $order->tickets);
        $this->assertCount(1, $order->tickets[0]->passes);
        $this->assertNotNull($order->tickets[0]->passes[0]->kode_qr);
    }

    public function test_order_gratis_menghasilkan_pass_per_sesi(): void
    {
        Queue::fake();
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $day1 = $event->sessions()->create(['label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100]);
        $day2 = $event->sessions()->create(['label' => 'Day 2', 'nama_session' => 'Puncak', 'urutan' => 2, 'kapasitas' => 100]);

        $gratis = $this->createTicketType($event, [
            'nama_tiket' => 'Dua Hari',
            'harga' => 0,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 1,
        ], [$day1->id, $day2->id]);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Tamu',
            'email' => 'tamu@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $gratis->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        $order = Order::where('kode_order', $kode)->firstOrFail();
        $this->assertCount(1, $order->tickets);
        $this->assertCount(2, $order->tickets[0]->passes);
        $this->assertEqualsCanonicalizing(
            [$day1->id, $day2->id],
            $order->tickets[0]->passes->pluck('event_session_id')->all(),
        );
    }

    public function test_jumlah_lewat_max_per_order_uang_tetap_dinilai(): void
    {
        Queue::fake();
        [$event, $gratis] = $this->scenario();

        // 2 tiket gratis = total tetap 0 → ikut jalur gratis.
        $res = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Tamu',
            'email' => 'tamu@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $gratis->id, 'jumlah' => 2]],
        ]);

        $res->assertCreated();
        $this->assertSame('lunas', $res->json('data.status'));
        $this->assertSame(0.0, (float) $res->json('data.total_harga'));
    }

    public function test_order_berbayar_tetap_pending_dan_minta_pembayaran(): void
    {
        Queue::fake();
        [$event, , $bayar] = $this->scenario();

        $res = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Tamu',
            'email' => 'tamu@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $bayar->id, 'jumlah' => 1]],
        ]);

        $res->assertCreated();
        // Tetap pending + dapat payment_url — jalur normal TIDAK berubah.
        $this->assertSame('pending', $res->json('data.status'));
        $this->assertNotNull($res->json('data.payment_url'));
    }

    public function test_kuota_terpotong_setelah_order_gratis(): void
    {
        Queue::fake();
        [$event, $gratis] = $this->scenario();

        $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Tamu',
            'email' => 'tamu@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $gratis->id, 'jumlah' => 2]],
        ])->assertCreated();

        // Kuota berkurang (reservasi + penjualan) sekaligus, karena order langsung lunas.
        $this->assertSame(8, $gratis->fresh()->sisa_kuota);
    }

    /**
     * Bukti rantai penuh: tiket gratis → QR terbit → bisa discan di gate.
     * Tanpa test ini, "QR tetap terbit" hanya asumsi dari jalur markPaid().
     */
    public function test_qr_tiket_gratis_bisa_discan_di_gate(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $day1 = $event->sessions()->create(['label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100]);
        $gate = $organizer->gates()->create(['nama_gate' => 'Gate A', 'status' => 'aktif']);

        $gratis = $this->createTicketType($event, [
            'nama_tiket' => 'Masuk Gratis',
            'harga' => 0,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 1,
        ], [$day1->id]);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Tamu Gratis',
            'email' => 'tamu@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $gratis->id, 'jumlah' => 1]],
        ])->assertCreated()->json('data.kode_order');

        $order = Order::where('kode_order', $kode)->with('tickets.passes')->firstOrFail();
        $pass = $order->tickets->first()->passes->first();
        $this->assertNotNull($pass->kode_qr);

        Sanctum::actingAs($user);

        // Scan pertama: valid (tiket gratis punya QR yang sah).
        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id,
            'session_id' => $day1->id,
            'kode_qr' => $pass->kode_qr,
            'gate_id' => $gate->id,
        ])->assertOk()->assertJsonPath('status', 'valid');

        // Scan kedua: sudah dipakai — anti double-entry tetap berlaku.
        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id,
            'session_id' => $day1->id,
            'kode_qr' => $pass->kode_qr,
        ])->assertJsonPath('status', 'used');
    }
}
