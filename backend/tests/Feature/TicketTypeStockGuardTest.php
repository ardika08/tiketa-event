<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Kuota tersisa (`ticket_types.sisa_kuota`) adalah angka TURUNAN: seharusnya
 * selalu sama dengan `kuota` dikurangi tiket yang masih dipegang order.
 *
 * Sebelum ini klien mana pun yang mengirim `sisa_kuota` ke endpoint kategori
 * tiket bisa merusak angka itu — kelas penyebab kuota "nyangkut" (3 kursi
 * bocor dari order kadaluarsa 2026-10-06).
 */
class TicketTypeStockGuardTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.mayar.callback_token', null);
    }

    public function test_klien_tidak_bisa_menimpa_sisa_kuota(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $jenis = $this->createTicketType($event, ['kuota' => 10, 'sisa_kuota' => 10]);

        Sanctum::actingAs($user);

        $this->putJson("/api/partner/ticket-types/{$jenis->id}", [
            'sisa_kuota' => 99,
        ])->assertOk();

        $this->assertSame(10, $jenis->fresh()->sisa_kuota);
    }

    public function test_ubah_kuota_menghitung_ulang_dari_tiket_terpakai(): void
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $event->sessions()->create([
            'label' => 'Day 1', 'nama_session' => 'Pembuka', 'urutan' => 1, 'kapasitas' => 100,
        ]);
        $jenis = $this->createTicketType($event, ['kuota' => 10, 'sisa_kuota' => 10], [$sesi->id]);

        // 2 tiket dipesan (belum dibayar) -> kuota tersisa 8.
        $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => 'budi@mail.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $jenis->id, 'jumlah' => 2]],
        ])->assertCreated();

        $this->assertSame(8, $jenis->fresh()->sisa_kuota);

        Sanctum::actingAs($user);

        // Kuota dinaikkan 10 -> 12: 2 tiket terpakai tetap terhitung, dan
        // sisa_kuota=999 yang dikirim klien HARUS diabaikan.
        $this->putJson("/api/partner/ticket-types/{$jenis->id}", [
            'kuota' => 12,
            'sisa_kuota' => 999,
        ])->assertOk();

        $this->assertSame(10, $jenis->fresh()->sisa_kuota);
    }

    public function test_reconcile_read_only_tidak_mengubah_dan_execute_mengoreksi(): void
    {
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $jenis = $this->createTicketType($event, ['kuota' => 10, 'sisa_kuota' => 4]);

        // Tanpa --execute: hanya melaporkan, angka tidak disentuh.
        $this->artisan('nontix:reconcile-stock')->assertExitCode(0);
        $this->assertSame(4, $jenis->fresh()->sisa_kuota);

        // Dengan --execute: dikembalikan ke angka turunan dari data order.
        $this->artisan('nontix:reconcile-stock', ['--execute' => true])->assertExitCode(0);
        $this->assertSame(10, $jenis->fresh()->sisa_kuota);
    }
}
