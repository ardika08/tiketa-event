<?php

namespace Tests\Feature;

use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Link checkout langsung per tiket: /checkout/{eventSlug}/{ticketSlug}
 * Backend-nya: GET /api/events/{slug}/tiket/{ticketSlug}
 *
 * Yang dijamin di sini:
 *  - link mengembalikan SATU tiket saja (pembeli tidak lihat tiket lain)
 *  - tiket sembunyi TETAP bisa dibuka lewat link (justru tujuan link ini)
 *  - tiket nonaktif / slug salah ditolak 404
 *  - harga & kuota yang dikirim adalah data terbaru dari DB
 */
class LinkCheckoutTiketTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    public function test_slug_tiket_dibuat_otomatis_dari_nama(): void
    {
        [, $partner] = $this->createPartner();
        $event = $this->createEvent($partner);

        $ticket = TicketType::create([
            'event_id' => $event->id,
            'nama_tiket' => 'Supermommils Race FREE',
            'harga' => 0,
            'kuota' => 50,
            'sisa_kuota' => 50,
            'max_per_order' => 1,
            'status' => 'aktif',
        ]);

        $this->assertSame('supermommils-race-free', $ticket->slug);
    }

    public function test_slug_duplikat_dalam_satu_event_diberi_akhiran(): void
    {
        [, $partner] = $this->createPartner();
        $event = $this->createEvent($partner);

        $buat = fn () => TicketType::create([
            'event_id' => $event->id,
            'nama_tiket' => 'VIP',
            'harga' => 100000,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 2,
            'status' => 'aktif',
        ]);

        $this->assertSame('vip', $buat()->slug);
        // Nama sama di event yang sama tidak boleh menabrak unique index.
        $this->assertSame('vip-2', $buat()->slug);
    }

    public function test_slug_sama_boleh_dipakai_event_berbeda(): void
    {
        [, $partner] = $this->createPartner();
        $eventA = $this->createEvent($partner, ['nama_event' => 'Event A']);
        $eventB = $this->createEvent($partner, ['nama_event' => 'Event B']);

        $a = TicketType::create([
            'event_id' => $eventA->id, 'nama_tiket' => 'Day 1', 'harga' => 0,
            'kuota' => 10, 'sisa_kuota' => 10, 'max_per_order' => 1, 'status' => 'aktif',
        ]);
        $b = TicketType::create([
            'event_id' => $eventB->id, 'nama_tiket' => 'Day 1', 'harga' => 0,
            'kuota' => 10, 'sisa_kuota' => 10, 'max_per_order' => 1, 'status' => 'aktif',
        ]);

        $this->assertSame('day-1', $a->slug);
        $this->assertSame('day-1', $b->slug);
    }

    public function test_link_mengembalikan_satu_tiket_saja(): void
    {
        [, $partner] = $this->createPartner();
        $event = $this->createEvent($partner);

        $target = $this->createTicketType($event, ['nama_tiket' => 'Day 1 Experience A']);
        $this->createTicketType($event, ['nama_tiket' => 'Day 2 Experience B']);
        $this->createTicketType($event, ['nama_tiket' => 'VIP Gala']);

        $res = $this->getJson("/api/events/{$event->slug}/tiket/{$target->slug}");

        $res->assertOk();
        $tiket = $res->json('data.tiket');
        $this->assertCount(1, $tiket, 'Link checkout harus mengembalikan tepat satu tiket.');
        $this->assertSame($target->id, $tiket[0]['id']);
        $this->assertSame('day-1-experience-a', $tiket[0]['slug']);
    }

    public function test_tiket_sembunyi_tetap_bisa_dibuka_lewat_link(): void
    {
        [, $partner] = $this->createPartner();
        $event = $this->createEvent($partner);

        $ticket = $this->createTicketType($event, [
            'nama_tiket' => 'Undangan Komplemen',
            'sembunyi' => true,
        ]);

        // Halaman event biasa: tiket sembunyi TIDAK muncul.
        $publik = $this->getJson("/api/events/{$event->slug}");
        $publik->assertOk();
        $namaPublik = collect($publik->json('data.tiket'))->pluck('nama_tiket')->all();
        $this->assertNotContains('Undangan Komplemen', $namaPublik);

        // Link langsung: tiket sembunyi HARUS muncul — itu tujuan fiturnya.
        $langsung = $this->getJson("/api/events/{$event->slug}/tiket/{$ticket->slug}");
        $langsung->assertOk();
        $this->assertCount(1, $langsung->json('data.tiket'));
        $this->assertSame('Undangan Komplemen', $langsung->json('data.tiket.0.nama_tiket'));
        $this->assertTrue($langsung->json('data.tiket.0.sembunyi'));
    }

    public function test_tiket_nonaktif_ditolak_404(): void
    {
        [, $partner] = $this->createPartner();
        $event = $this->createEvent($partner);

        $ticket = $this->createTicketType($event, [
            'nama_tiket' => 'Tiket Dihentikan',
            'status' => 'nonaktif',
        ]);

        $this->getJson("/api/events/{$event->slug}/tiket/{$ticket->slug}")->assertNotFound();
    }

    public function test_slug_tiket_salah_atau_milik_event_lain_ditolak_404(): void
    {
        [, $partner] = $this->createPartner();
        $eventA = $this->createEvent($partner, ['nama_event' => 'Event A']);
        $eventB = $this->createEvent($partner, ['nama_event' => 'Event B']);

        $this->createTicketType($eventA, ['nama_tiket' => 'Hanya Di A']);

        $this->getJson("/api/events/{$eventA->slug}/tiket/tidak-ada")->assertNotFound();
        // Slug milik event lain tidak boleh bocor lewat event ini.
        $this->getJson("/api/events/{$eventB->slug}/tiket/hanya-di-a")->assertNotFound();
    }

    public function test_link_membawa_harga_dan_kuota_terbaru(): void
    {
        [, $partner] = $this->createPartner();
        $event = $this->createEvent($partner);

        $ticket = $this->createTicketType($event, [
            'nama_tiket' => 'Presale',
            'harga' => 150000,
            'kuota' => 20,
            'sisa_kuota' => 7,
        ]);

        $res = $this->getJson("/api/events/{$event->slug}/tiket/{$ticket->slug}");

        $res->assertOk();
        $this->assertSame(150000.0, (float) $res->json('data.tiket.0.harga'));
        $this->assertSame(7, (int) $res->json('data.tiket.0.sisa_kuota'));
        $this->assertSame($event->slug, $res->json('data.slug'));
    }

    public function test_tiket_sembunyi_tetap_bisa_dipesan_lewat_checkout(): void
    {
        [, $partner] = $this->createPartner();
        $event = $this->createEvent($partner);
        $ticket = $this->createTicketType($event, [
            'nama_tiket' => 'Undangan Komplemen',
            'harga' => 0,
            'kuota' => 5,
            'sisa_kuota' => 5,
            'sembunyi' => true,
        ]);

        $res = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Tamu Undangan',
            'email' => 'tamu@contoh.id',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $ticket->id, 'jumlah' => 1]],
        ]);

        $res->assertCreated();
    }
}
