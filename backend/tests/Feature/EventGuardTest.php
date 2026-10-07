<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Fase 1 + Fase 2 — pengaman integritas tiket.
 *
 * Fase 1: hapus sesi/event yang sudah ada penjualan harus DITOLAK, karena FK
 *         cascade akan ikut menghapus QR pembeli.
 * Fase 2: pass tanpa sesi (tiket cacat) boleh dipakai SEKALI di gate mana pun
 *         selama masih di event yang sama.
 */
class EventGuardTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.mayar.callback_token', null);
    }

    /** Buat kategori + pesanan lunas 1 tiket. */
    private function pesananLunas($event, int $sessionId): Order
    {
        $jenis = $this->createTicketType($event, [
            'nama_tiket' => 'Reguler', 'harga' => 100000, 'kuota' => 5, 'sisa_kuota' => 5,
        ], [$sessionId]);

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $jenis->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        return Order::where('kode_order', $kode)->with('tickets.passes')->firstOrFail();
    }

    public function test_hapus_sesi_ditolak_kalau_sudah_ada_tiket_terjual(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
        ]);

        $order = $this->pesananLunas($event, $sesi->id);
        $pass = $order->tickets->first()->passes->first();

        Sanctum::actingAs($user);

        $this->deleteJson("/api/partner/events/{$event->id}/sessions/{$sesi->id}")
            ->assertStatus(409)
            ->assertJsonPath('kode', 'sesi_punya_tiket')
            ->assertJsonPath('sesi.0.label', 'Day 1')
            ->assertJsonPath('sesi.0.jumlah_tiket', 1);

        // Yang terpenting: QR pembeli TIDAK hilang dan sesinya masih ada.
        $this->assertDatabaseHas('ticket_passes', [
            'id' => $pass->id,
            'kode_qr' => $pass->kode_qr,
            'event_session_id' => $sesi->id,
        ]);
        $this->assertDatabaseHas('event_sessions', ['id' => $sesi->id]);
    }

    public function test_hapus_sesi_tanpa_tiket_tetap_bisa(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $event->sessions()->create([
            'label' => 'Kosong', 'nama_session' => 'Kosong', 'urutan' => 1, 'kapasitas' => 10,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/partner/events/{$event->id}/sessions/{$sesi->id}")->assertOk();

        $this->assertDatabaseMissing('event_sessions', ['id' => $sesi->id]);
    }

    /**
     * Jalur yang BENAR-BENAR dipakai UI: form event menghapus sesi lewat
     * mass-delete di EventController::syncSessions(), bukan lewat destroySession.
     */
    public function test_form_event_tidak_bisa_menghapus_sesi_yang_punya_tiket(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $day1 = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
        ]);
        $day2 = $event->sessions()->create([
            'label' => 'Day 2', 'nama_session' => 'Puncak', 'urutan' => 2, 'kapasitas' => 100,
        ]);

        $order = $this->pesananLunas($event, $day1->id);
        $pass = $order->tickets->first()->passes->first();

        Sanctum::actingAs($user);

        // Partner menekan tombol tong sampah pada baris "Day 1" lalu menyimpan.
        $this->putJson("/api/partner/events/{$event->id}", [
            'nama_event' => 'Event Test',
            'sessions' => [
                ['id' => $day2->id, 'label' => 'Day 2', 'nama_session' => 'Puncak', 'urutan' => 1],
            ],
        ])->assertStatus(409)->assertJsonPath('kode', 'sesi_punya_tiket');

        // Sesi dan QR pembeli harus UTUH.
        $this->assertDatabaseHas('event_sessions', ['id' => $day1->id]);
        $this->assertDatabaseHas('ticket_passes', [
            'id' => $pass->id,
            'kode_qr' => $pass->kode_qr,
            'event_session_id' => $day1->id,
        ]);
    }

    public function test_form_event_tetap_bisa_menghapus_sesi_tanpa_tiket(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $day1 = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
        ]);
        $day2 = $event->sessions()->create([
            'label' => 'Day 2', 'nama_session' => 'Puncak', 'urutan' => 2, 'kapasitas' => 100,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/partner/events/{$event->id}", [
            'nama_event' => 'Event Test',
            'sessions' => [
                ['id' => $day1->id, 'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('event_sessions', ['id' => $day1->id]);
        $this->assertDatabaseMissing('event_sessions', ['id' => $day2->id]);
    }

    public function test_hapus_kategori_ditolak_kalau_sudah_ada_tiket_terjual(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
        ]);

        $order = $this->pesananLunas($event, $sesi->id);
        $pass = $order->tickets->first()->passes->first();
        $kategoriId = $order->tickets->first()->ticket_type_id;

        Sanctum::actingAs($user);

        $this->deleteJson("/api/partner/ticket-types/{$kategoriId}")
            ->assertStatus(409)
            ->assertJsonPath('kode', 'kategori_punya_tiket')
            ->assertJsonPath('jumlah_tiket', 1);

        $this->assertDatabaseHas('ticket_types', ['id' => $kategoriId]);
        $this->assertDatabaseHas('ticket_passes', ['id' => $pass->id, 'kode_qr' => $pass->kode_qr]);
    }

    public function test_hapus_kategori_tanpa_tiket_tetap_bisa(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
        ]);
        $kategori = $this->createTicketType($event, [
            'nama_tiket' => 'Kosong', 'kuota' => 5, 'sisa_kuota' => 5,
        ], [$sesi->id]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/partner/ticket-types/{$kategori->id}")->assertOk();

        $this->assertDatabaseMissing('ticket_types', ['id' => $kategori->id]);
    }

    public function test_hapus_event_ditolak_kalau_sudah_ada_tiket_terjual(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
        ]);

        $this->pesananLunas($event, $sesi->id);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/partner/events/{$event->id}")
            ->assertStatus(409)
            ->assertJsonPath('kode', 'event_punya_tiket')
            ->assertJsonPath('jumlah_tiket', 1);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'deleted_at' => null]);
    }

    public function test_hapus_event_tanpa_tiket_tetap_bisa(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/partner/events/{$event->id}")->assertOk();
    }

    public function test_pass_tanpa_sesi_bisa_discan_sekali_di_gate_mana_pun(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
        ]);

        $order = $this->pesananLunas($event, $sesi->id);
        $pass = $order->tickets->first()->passes->first();

        // Simulasikan tiket cacat: kategori tanpa sesi -> pass tanpa sesi.
        $pass->update(['event_session_id' => null]);

        Sanctum::actingAs($user);

        // Dulu SELALU ditolak "QR bukan untuk sesi ini"; sekarang boleh masuk.
        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $sesi->id, 'kode_qr' => $pass->kode_qr,
        ])->assertOk()->assertJsonPath('status', 'valid');

        // Tetap sekali pakai.
        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id, 'session_id' => $sesi->id, 'kode_qr' => $pass->kode_qr,
        ])->assertJsonPath('status', 'used');
    }

    public function test_pass_tanpa_sesi_dari_event_lain_tetap_ditolak(): void
    {
        Queue::fake();
        [$user, $organizer] = $this->createPartner();
        $eventA = $this->createEvent($organizer);
        $sesiA = $eventA->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
        ]);

        $eventB = $this->createEvent($organizer, ['nama_event' => 'Event Lain']);
        $sesiB = $eventB->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
        ]);

        $order = $this->pesananLunas($eventA, $sesiA->id);
        $pass = $order->tickets->first()->passes->first();
        $pass->update(['event_session_id' => null]);

        Sanctum::actingAs($user);

        // Pass tanpa sesi milik event A TIDAK boleh dipakai di gate event B.
        $this->postJson('/api/partner/scan', [
            'event_id' => $eventB->id, 'session_id' => $sesiB->id, 'kode_qr' => $pass->kode_qr,
        ])->assertJsonPath('status', 'wrong_session');
    }
}
