<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    /**
     * Gelombang jam masuk per kategori: sebelum jam ditolak, di dalam jam lolos,
     * setelah jam ditolak — semuanya tanpa perlu petugas memilih apa pun.
     */
    public function test_scan_enforces_entry_window_per_ticket_category(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        $day1 = $event->sessions()->create(['label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100]);
        $baby = $this->createTicketType($event, [
            'nama_tiket' => 'Baby Shower',
            'kuota' => 5,
            'sisa_kuota' => 5,
            'jam_masuk_mulai' => '08:00',
            'jam_masuk_selesai' => '10:00',
        ], [$day1->id]);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $baby->id, 'jumlah' => 2]],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $passes = Order::where('kode_order', $kode)->with('tickets.passes')->firstOrFail()
            ->tickets->flatMap->passes->values();

        Sanctum::actingAs($user);

        // 07:12 — tiket Baby Shower (08:00–10:00) belum boleh masuk.
        $this->travelTo(Carbon::parse('2026-11-15 07:12:00'));

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => $passes[0]->kode_qr,
        ])->assertOk()
            ->assertJsonPath('status', 'not_open')
            ->assertJsonPath('ticket.nama_tiket', 'Baby Shower')
            ->assertJsonPath('ticket.jam_masuk_mulai', '08:00')
            ->assertJsonPath('ticket.jam_masuk_selesai', '10:00');

        // 09:00 — di dalam jendela jam: lolos.
        $this->travelTo(Carbon::parse('2026-11-15 09:00:00'));

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => $passes[0]->kode_qr,
        ])->assertOk()->assertJsonPath('status', 'valid');

        // 11:00 — jendela sudah tutup: ditolak walau tiket ini belum pernah dipakai.
        $this->travelTo(Carbon::parse('2026-11-15 11:00:00'));

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => $passes[1]->kode_qr,
        ])->assertOk()->assertJsonPath('status', 'closed');

        // Penolakan tetap tercatat untuk audit.
        $this->assertSame(2, \App\Models\Checkin::where('status', 'gagal')->count());
    }

    /** Kategori tanpa jam masuk tidak dibatasi jam sama sekali (tiket lama aman). */
    public function test_scan_allows_category_without_entry_window(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        $day1 = $event->sessions()->create(['label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100]);
        $reguler = $this->createTicketType($event, ['nama_tiket' => 'Reguler', 'kuota' => 5, 'sisa_kuota' => 5], [$day1->id]);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $reguler->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $pass = Order::where('kode_order', $kode)->with('tickets.passes')->firstOrFail()
            ->tickets->first()->passes->first();

        Sanctum::actingAs($user);

        // Jam 03:00 — kalau kategori punya jam masuk, ini pasti ditolak.
        $this->travelTo(Carbon::parse('2026-11-15 03:00:00'));

        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $day1->id, 'kode_qr' => $pass->kode_qr,
        ])->assertOk()->assertJsonPath('status', 'valid');
    }

    /**
     * Sesi diambil dari QR, bukan dari pilihan petugas. Halaman scan tidak lagi
     * mengirim session_id, dan sesi yang tercatat harus sesi milik QR itu.
     */
    public function test_scan_takes_session_from_qr_without_manual_selection(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        $day1 = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
            'tanggal_mulai' => Carbon::parse('2026-11-15 08:00:00'),
            'tanggal_selesai' => Carbon::parse('2026-11-15 20:00:00'),
        ]);
        $reguler = $this->createTicketType($event, ['nama_tiket' => 'Reguler', 'kuota' => 5, 'sisa_kuota' => 5], [$day1->id]);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $reguler->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $pass = Order::where('kode_order', $kode)->with('tickets.passes')->firstOrFail()
            ->tickets->first()->passes->first();

        Sanctum::actingAs($user);
        $this->travelTo(Carbon::parse('2026-11-15 09:00:00'));

        // session_id SENGAJA tidak dikirim.
        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'kode_qr' => $pass->kode_qr,
        ])->assertOk()
            ->assertJsonPath('status', 'valid')
            ->assertJsonPath('ticket.sesi_label', 'Day 1');

        $this->assertDatabaseHas('checkins', [
            'kode_qr' => $pass->kode_qr,
            'status' => 'berhasil',
            'event_session_id' => $day1->id,
        ]);
    }

    /**
     * Kunci hari otomatis: tiket Day 2 yang dipindai di hari Day 1 ditolak dengan
     * alasan yang jelas (sesinya belum dibuka), bukan "QR bukan untuk sesi ini".
     */
    public function test_scan_rejects_ticket_belonging_to_another_day(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        $day1 = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
            'tanggal_mulai' => Carbon::parse('2026-11-15 08:00:00'),
            'tanggal_selesai' => Carbon::parse('2026-11-15 20:00:00'),
        ]);
        $day2 = $event->sessions()->create([
            'label' => 'Day 2', 'nama_session' => 'Puncak', 'urutan' => 2, 'kapasitas' => 100,
            'tanggal_mulai' => Carbon::parse('2026-11-16 08:00:00'),
            'tanggal_selesai' => Carbon::parse('2026-11-16 20:00:00'),
        ]);

        $bundle = $this->createTicketType($event, [
            'nama_tiket' => '2-Day Pass', 'harga' => 180000, 'kuota' => 5, 'sisa_kuota' => 5,
        ], [$day1->id, $day2->id]);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $bundle->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $ticket = Order::where('kode_order', $kode)->with('tickets.passes')->firstOrFail()->tickets->first();
        $day1Pass = $ticket->passes->firstWhere('event_session_id', $day1->id);
        $day2Pass = $ticket->passes->firstWhere('event_session_id', $day2->id);

        Sanctum::actingAs($user);
        $this->travelTo(Carbon::parse('2026-11-15 09:00:00'));

        $tolak = $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'kode_qr' => $day2Pass->kode_qr,
        ])->assertOk()->assertJsonPath('status', 'not_open');

        $this->assertStringContainsString('Day 2', $tolak->json('message'));

        // Tiket Day 1 di hari Day 1 tetap lolos walau session_id tidak dikirim.
        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'kode_qr' => $day1Pass->kode_qr,
        ])->assertOk()->assertJsonPath('status', 'valid');
    }

    /**
     * Panel gelombang di halaman scan: sesi berjalan terpilih otomatis dan tiap
     * kategori melaporkan status jam masuknya sendiri.
     */
    public function test_scan_schedule_endpoint_reports_waves_and_auto_session(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        $day1 = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
            'tanggal_mulai' => Carbon::parse('2026-11-15 08:00:00'),
            'tanggal_selesai' => Carbon::parse('2026-11-15 20:00:00'),
        ]);
        $day2 = $event->sessions()->create([
            'label' => 'Day 2', 'nama_session' => 'Puncak', 'urutan' => 2, 'kapasitas' => 100,
            'tanggal_mulai' => Carbon::parse('2026-11-16 08:00:00'),
            'tanggal_selesai' => Carbon::parse('2026-11-16 20:00:00'),
        ]);

        $baby = $this->createTicketType($event, [
            'nama_tiket' => 'Baby Shower', 'kuota' => 5, 'sisa_kuota' => 5,
            'jam_masuk_mulai' => '08:00', 'jam_masuk_selesai' => '10:00',
        ], [$day1->id]);
        $reguler = $this->createTicketType($event, [
            'nama_tiket' => 'Reguler', 'kuota' => 5, 'sisa_kuota' => 5,
            'jam_masuk_mulai' => '10:00', 'jam_masuk_selesai' => '12:00',
        ], [$day1->id]);
        $this->createTicketType($event, ['nama_tiket' => 'VIP', 'kuota' => 5, 'sisa_kuota' => 5], [$day1->id]);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [
                ['ticket_type_id' => $baby->id, 'jumlah' => 2],
                ['ticket_type_id' => $reguler->id, 'jumlah' => 2],
            ],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        $babyPass = Order::where('kode_order', $kode)->with('tickets.passes')->firstOrFail()
            ->tickets->firstWhere('ticket_type_id', $baby->id)->passes->first();

        Sanctum::actingAs($user);
        $this->travelTo(Carbon::parse('2026-11-15 09:00:00'));

        // Satu tiket Baby Shower sudah masuk pada gelombangnya.
        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'kode_qr' => $babyPass->kode_qr,
        ])->assertOk()->assertJsonPath('status', 'valid');

        $respon = $this->getJson("/api/partner/scan/sessions/{$event->id}")->assertOk();

        $this->assertTrue($respon->json('ada_jadwal_sesi'));
        $this->assertSame($day1->id, $respon->json('sesi_aktif_id'));

        $sesi = collect($respon->json('data'))->keyBy('label');
        $this->assertSame('berjalan', $sesi['Day 1']['status_waktu']);
        $this->assertSame('akan_datang', $sesi['Day 2']['status_waktu']);

        $kategori = collect($respon->json('kategori'))->keyBy('nama_tiket');
        $this->assertSame('dibuka', $kategori['Baby Shower']['status']);
        $this->assertSame(2, $kategori['Baby Shower']['total_pass']);
        $this->assertSame(1, $kategori['Baby Shower']['total_hadir']);
        $this->assertSame('belum_dibuka', $kategori['Reguler']['status']);
        $this->assertSame(60, $kategori['Reguler']['menit_ke_buka']);
        $this->assertSame('tanpa_jadwal', $kategori['VIP']['status']);

        // Gelombang berikutnya otomatis berubah status begitu jamnya tiba.
        $this->travelTo(Carbon::parse('2026-11-15 10:30:00'));

        $kategori = collect(
            $this->getJson("/api/partner/scan/sessions/{$event->id}")->json('kategori')
        )->keyBy('nama_tiket');

        $this->assertSame('sudah_ditutup', $kategori['Baby Shower']['status']);
        $this->assertSame('dibuka', $kategori['Reguler']['status']);
    }
}
