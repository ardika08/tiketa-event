<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Status nonaktif pada jenis tiket.
 *
 * Status ini dipakai partner untuk menghentikan penjualan tanpa menghapus
 * tiket yang sudah punya pembeli. Sebelum ini kolomnya divalidasi tetapi
 * tidak ditegakkan di mana pun: tiket nonaktif tetap tampil di halaman publik
 * dan masih bisa dibeli lewat ID lama. Test ini mengunci kedua jalur itu.
 */
class TiketNonaktifTest extends TestCase
{
    use InteractsWithNontix;
    use RefreshDatabase;

    /** @return array{0: \App\Models\Organizer, 1: Event, 2: TicketType} */
    private function skenario(): array
    {
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer, ['slug' => 'event-nonaktif-uji']);
        $sesi = $event->sessions()->create([
            'label' => 'Day 1',
            'nama_session' => 'Sesi Pagi',
            'urutan' => 1,
            'kapasitas' => 100,
        ]);
        $kategori = $this->createTicketType($event, ['nama_tiket' => 'Reguler'], [$sesi->id]);

        return [$organizer, $event, $kategori];
    }

    public function test_tiket_nonaktif_tidak_muncul_di_halaman_publik(): void
    {
        [, $event, $aktif] = $this->skenario();
        $nonaktif = $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket Lama',
            'harga' => 50000,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 2,
            'status' => 'nonaktif',
        ]);

        $res = $this->getJson('/api/events/'.$event->slug);

        $res->assertOk();
        $ids = collect($res->json('data.tiket'))->pluck('id')->all();
        $this->assertContains($aktif->id, $ids, 'Tiket aktif harus tetap tampil.');
        $this->assertNotContains($nonaktif->id, $ids, 'Tiket nonaktif tidak boleh tampil di publik.');
    }

    public function test_total_sisa_kuota_tidak_menghitung_tiket_nonaktif(): void
    {
        [, $event, $aktif] = $this->skenario();
        $aktif->update(['kuota' => 10, 'sisa_kuota' => 4]);
        $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket Lama',
            'harga' => 50000,
            'kuota' => 100,
            'sisa_kuota' => 100,
            'max_per_order' => 2,
            'status' => 'nonaktif',
        ]);

        $res = $this->getJson('/api/events/'.$event->slug);

        // Kalau tiket nonaktif ikut dijumlah, event yang sudah habis bisa
        // tampak masih tersedia di beranda.
        $this->assertSame(4, $res->json('data.total_sisa_kuota'));
    }

    public function test_pembeli_tidak_bisa_membeli_tiket_nonaktif(): void
    {
        [, $event, $kategori] = $this->skenario();
        $kategori->update(['status' => 'nonaktif']);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->create(
            $event,
            ['nama' => 'Pembeli Uji', 'email' => 'pembeli@contoh.id', 'whatsapp' => '081200000001'],
            [['ticket_type_id' => $kategori->id, 'jumlah' => 1]],
        );
    }

    public function test_partner_tidak_bisa_menjual_manual_tiket_nonaktif(): void
    {
        [, $event, $kategori] = $this->skenario();
        $kategori->update(['status' => 'nonaktif']);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->createManual(
            $event,
            ['nama' => 'Pembeli Uji', 'email' => 'pembeli@contoh.id', 'whatsapp' => '081200000001'],
            [['ticket_type_id' => $kategori->id, 'jumlah' => 1]],
            'tunai',
            'Booth',
        );
    }

    public function test_tiket_nonaktif_tetap_tampil_di_kelola_partner(): void
    {
        [$organizer, $event] = $this->skenario();
        $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket Lama',
            'harga' => 50000,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 2,
            'status' => 'nonaktif',
        ]);

        $user = \App\Models\User::where('organizer_id', $organizer->id)->firstOrFail();
        $res = $this->actingAs($user)->getJson('/api/partner/events/'.$event->id);

        // Partner harus tetap melihat tiketnya supaya bisa diaktifkan kembali.
        $res->assertOk();
        $nama = collect($res->json('data.tiket'))->pluck('nama_tiket')->all();
        $this->assertContains('Tiket Lama', $nama);
    }

    public function test_tiket_yang_sudah_terjual_tidak_bisa_dihapus(): void
    {
        [$organizer, $event, $kategori] = $this->skenario();

        app(OrderService::class)->createManual(
            $event,
            ['nama' => 'Pembeli Uji', 'email' => 'pembeli@contoh.id', 'whatsapp' => '081200000001'],
            [['ticket_type_id' => $kategori->id, 'jumlah' => 1]],
            'tunai',
            'Booth',
        );

        $user = \App\Models\User::where('organizer_id', $organizer->id)->firstOrFail();
        $res = $this->actingAs($user)->deleteJson('/api/partner/ticket-types/'.$kategori->id);

        $res->assertStatus(409);
        $this->assertSame('kategori_punya_tiket', $res->json('kode'));
        $this->assertDatabaseHas('ticket_types', ['id' => $kategori->id]);
    }

    public function test_tiket_tanpa_pembeli_bisa_dihapus(): void
    {
        [$organizer, , $kategori] = $this->skenario();

        $user = \App\Models\User::where('organizer_id', $organizer->id)->firstOrFail();
        $res = $this->actingAs($user)->deleteJson('/api/partner/ticket-types/'.$kategori->id);

        $res->assertOk();
        $this->assertDatabaseMissing('ticket_types', ['id' => $kategori->id]);
    }
}
