<?php

namespace Tests\Feature;

use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Fase 3 — kategori tiket WAJIB punya minimal 1 sesi.
 *
 * Kategori tanpa sesi menghasilkan pass dengan event_session_id = NULL, dan pass
 * seperti itu tidak bisa dipakai check-in di gate mana pun -> tiket pembeli mati.
 */
class TicketSessionRequirementTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nama_tiket' => 'Reguler',
            'harga' => 100000,
            'kuota' => 10,
            'max_per_order' => 4,
        ], $overrides);
    }

    private function buatSesi($event, string $label = 'Day 1')
    {
        return $event->sessions()->create([
            'label' => $label,
            'nama_session' => 'Pembuka',
            'urutan' => 1,
            'kapasitas' => 100,
        ]);
    }

    public function test_buat_kategori_tanpa_sesi_ditolak_dan_tidak_menyisakan_baris(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $this->buatSesi($event);

        Sanctum::actingAs($user);

        $sebelum = TicketType::count();

        $this->postJson('/api/partner/ticket-types', $this->payload([
            'event_id' => $event->id,
            'session_ids' => [],
        ]))->assertStatus(422)->assertJsonValidationErrors('session_ids');

        // Transaksi harus dibatalkan: tidak boleh tertinggal kategori tanpa sesi.
        $this->assertSame($sebelum, TicketType::count());
    }

    public function test_buat_kategori_dengan_sesi_berhasil(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $this->buatSesi($event);

        Sanctum::actingAs($user);

        $this->postJson('/api/partner/ticket-types', $this->payload([
            'event_id' => $event->id,
            'session_ids' => [$sesi->id],
        ]))->assertStatus(201);

        $kategori = TicketType::latest('id')->first();
        $this->assertTrue($kategori->sessions()->whereKey($sesi->id)->exists());
    }

    public function test_ubah_kategori_menjadi_tanpa_sesi_ditolak_dan_field_tidak_ikut_berubah(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $this->buatSesi($event);
        $kategori = $this->createTicketType($event, ['nama_tiket' => 'Reguler'], [$sesi->id]);

        Sanctum::actingAs($user);

        $this->putJson("/api/partner/ticket-types/{$kategori->id}", [
            'nama_tiket' => 'DIUBAH',
            'session_ids' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('session_ids');

        // Rollback: perubahan field tidak boleh ikut tersimpan, sesi tetap terpasang.
        $segar = $kategori->fresh();
        $this->assertSame('Reguler', $segar->nama_tiket);
        $this->assertTrue($segar->sessions()->whereKey($sesi->id)->exists());
    }

    public function test_sesi_dari_event_lain_ditolak(): void
    {
        [$user, $organizer] = $this->createPartner();
        $eventA = $this->createEvent($organizer);
        $eventB = $this->createEvent($organizer, [
            'nama_event' => 'Event Lain',
            'slug' => 'event-lain-'.uniqid(),
        ]);
        $sesiB = $this->buatSesi($eventB, 'Day B');

        Sanctum::actingAs($user);

        $sebelum = TicketType::count();

        $this->postJson('/api/partner/ticket-types', $this->payload([
            'event_id' => $eventA->id,
            'session_ids' => [$sesiB->id],
        ]))->assertStatus(422)->assertJsonValidationErrors('session_ids');

        $this->assertSame($sebelum, TicketType::count());
    }

    public function test_sync_sesi_kosong_ditolak(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $this->buatSesi($event);
        $kategori = $this->createTicketType($event, [], [$sesi->id]);

        Sanctum::actingAs($user);

        $this->putJson("/api/partner/ticket-types/{$kategori->id}/sessions", [
            'session_ids' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('session_ids');

        $this->assertTrue($kategori->fresh()->sessions()->whereKey($sesi->id)->exists());
    }
}
