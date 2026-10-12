<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

class CheckinTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.mayar.callback_token', null);
    }

    public function test_scan_validates_per_session_and_reports_attendance(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        $day1 = $event->sessions()->create(['label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100]);
        $day2 = $event->sessions()->create(['label' => 'Day 2', 'nama_session' => 'Puncak', 'urutan' => 2, 'kapasitas' => 100]);
        $bundle = $this->createTicketType($event, ['nama_tiket' => '2-Day Pass', 'harga' => 180000, 'kuota' => 5, 'sisa_kuota' => 5], [$day1->id, $day2->id]);
        $gate = $organizer->gates()->create(['nama_gate' => 'Gate A', 'status' => 'aktif']);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $bundle->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $order = Order::where('kode_order', $kode)->with('tickets.passes')->firstOrFail();
        $ticket = $order->tickets->first();
        $day1Pass = $ticket->passes->firstWhere('event_session_id', $day1->id);
        $day2Pass = $ticket->passes->firstWhere('event_session_id', $day2->id);

        Sanctum::actingAs($user);

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id,
            'kode_qr' => $day1Pass->kode_qr, 'gate_id' => $gate->id,
        ])->assertOk()->assertJsonPath('status', 'valid');

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => $day1Pass->kode_qr,
        ])->assertJsonPath('status', 'used');

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => $day2Pass->kode_qr,
        ])->assertJsonPath('status', 'wrong_session');

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => 'XXXX-XXXX-XXXX',
        ])->assertJsonPath('status', 'invalid');

        $this->assertDatabaseHas('checkins', ['kode_qr' => $day1Pass->kode_qr, 'status' => 'berhasil']);

        $this->getJson("/api/partner/attendance?event_id={$event->id}")
            ->assertOk()
            ->assertJsonStructure(['sessions', 'kategori', 'checkins']);
    }

    public function test_attendance_reports_per_category_breakdown(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        $day1 = $event->sessions()->create(['label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100]);
        $day2 = $event->sessions()->create(['label' => 'Day 2', 'nama_session' => 'Puncak', 'urutan' => 2, 'kapasitas' => 100]);

        $reguler = $this->createTicketType($event, ['nama_tiket' => 'Reguler', 'harga' => 100000, 'kuota' => 5, 'sisa_kuota' => 5], [$day1->id]);
        $bundle = $this->createTicketType($event, ['nama_tiket' => '2-Day Pass', 'harga' => 180000, 'kuota' => 5, 'sisa_kuota' => 5], [$day1->id, $day2->id]);
        // Kategori tanpa penjualan: tetap harus terkirim dengan angka 0.
        $this->createTicketType($event, ['nama_tiket' => 'VIP', 'harga' => 300000, 'kuota' => 3, 'sisa_kuota' => 3], [$day1->id]);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [
                ['ticket_type_id' => $reguler->id, 'jumlah' => 2],
                ['ticket_type_id' => $bundle->id, 'jumlah' => 1],
            ],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $order = Order::where('kode_order', $kode)->with('tickets.passes')->firstOrFail();
        $regulerPass = $order->tickets
            ->firstWhere('ticket_type_id', $reguler->id)
            ->passes
            ->firstWhere('event_session_id', $day1->id);

        Sanctum::actingAs($user);

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => $regulerPass->kode_qr,
        ])->assertJsonPath('status', 'valid');

        $kategori = collect(
            $this->getJson("/api/partner/attendance?event_id={$event->id}")
                ->assertOk()
                ->json('kategori')
        )->keyBy('nama_tiket');

        // Reguler: 2 tiket terjual, 1 sudah check-in.
        $this->assertSame(2, $kategori['Reguler']['total_pass']);
        $this->assertSame(1, $kategori['Reguler']['total_hadir']);
        $this->assertSame(50, $kategori['Reguler']['persentase']);

        // 2-Day Pass: 1 tiket = 1 pass per sesi, jadi 2 pass, belum ada yang hadir.
        $this->assertSame(2, $kategori['2-Day Pass']['total_pass']);
        $this->assertSame(0, $kategori['2-Day Pass']['total_hadir']);
        $this->assertSame(1, $kategori['2-Day Pass']['per_sesi'][(string) $day1->id]['total_pass']);
        $this->assertSame(1, $kategori['2-Day Pass']['per_sesi'][(string) $day2->id]['total_pass']);

        // Kategori tanpa penjualan tidak boleh hilang dari daftar.
        $this->assertSame(0, $kategori['VIP']['total_pass']);
        $this->assertSame(0, $kategori['VIP']['persentase']);

        // Total per kategori harus sama dengan total pass yang dipakai kartu statistik.
        $this->assertSame(4, $kategori->sum('total_pass'));
    }
}
