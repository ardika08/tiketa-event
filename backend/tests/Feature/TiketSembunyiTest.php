<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TicketType;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Tiket tersembunyi (sembunyi) — disembunyikan dari halaman publik, tapi
 * tetap bisa dijual lewat penjualan manual.
 *
 * Dipakai untuk tiket khusus: undangan, komplementer, OTS di lokasi. Partner
 * tidak mau tiket itu tampil sebagai pilihan di halaman event publik, tapi
 * tetap perlu menjualnya dan mengikatnya ke pesanan manual.
 *
 * Bedanya dari status nonaktif yang mudah tertukar:
 *   nonaktif = berhenti dijual, diblokir di checkout
 *   sembunyi = masih dijual, hanya disembunyikan dari daftar pilihan publik
 */
class TiketSembunyiTest extends TestCase
{
    use InteractsWithNontix;
    use RefreshDatabase;

    /** @return array{0: \App\Models\Organizer, 1: \App\Models\Event} */
    private function skenario(): array
    {
        [, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer, ['slug' => 'event-sembunyi-uji']);
        $event->sessions()->create([
            'label' => 'Day 1',
            'nama_session' => 'Sesi Pagi',
            'urutan' => 1,
            'kapasitas' => 100,
        ]);

        return [$organizer, $event];
    }

    public function test_tiket_sembunyi_tidak_muncul_di_halaman_publik(): void
    {
        [, $event] = $this->skenario();
        $tampil = $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket Publik',
            'harga' => 100000,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 2,
            'status' => 'aktif',
            'sembunyi' => false,
        ]);
        $sembunyi = $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket Undangan',
            'harga' => 0,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 1,
            'status' => 'aktif',
            'sembunyi' => true,
        ]);

        $res = $this->getJson('/api/events/'.$event->slug);

        $res->assertOk();
        $ids = collect($res->json('data.tiket'))->pluck('id')->all();
        $this->assertContains($tampil->id, $ids, 'Tiket biasa harus tetap tampil.');
        $this->assertNotContains($sembunyi->id, $ids, 'Tiket sembunyi tidak boleh tampil di publik.');
    }

    public function test_partner_tetap_melihat_tiket_sembunyi(): void
    {
        [, $event] = $this->skenario();
        $sembunyi = $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket Undangan',
            'harga' => 0,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 1,
            'status' => 'aktif',
            'sembunyi' => true,
        ]);

        $user = \App\Models\User::where('organizer_id', $event->organizer_id)->firstOrFail();
        $res = $this->actingAs($user)->getJson('/api/partner/events/'.$event->id);

        $res->assertOk();
        $baris = collect($res->json('data.tiket'))
            ->firstWhere('id', $sembunyi->id);

        $this->assertNotNull($baris, 'Partner harus tetap melihat tiket sembunyi.');
        $this->assertTrue($baris['sembunyi'], 'Penanda sembunyi harus terkirim ke partner.');
    }

    public function test_tiket_sembunyi_masih_bisa_dijual_manual(): void
    {
        [, $event] = $this->skenario();
        $sesi = $event->sessions()->first();
        $sembunyi = $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket Undangan',
            'harga' => 0,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 1,
            'status' => 'aktif',
            'sembunyi' => true,
        ]);
        $sembunyi->sessions()->attach($sesi->id);

        $order = app(OrderService::class)->createManual(
            $event,
            ['nama' => 'Tamu Undangan', 'email' => 'tamu@contoh.id', 'whatsapp' => '081200000002'],
            [['ticket_type_id' => $sembunyi->id, 'jumlah' => 1]],
            'tunai',
            'Booth',
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(9, $sembunyi->fresh()->sisa_kuota, 'Kuota harus berkurang.');
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'ticket_type_id' => $sembunyi->id]);
    }

    public function test_tiket_sembunyi_aktif_bisa_dibeli_publik_lewat_id_langsung(): void
    {
        [, $event] = $this->skenario();
        $sembunyi = $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket Undangan',
            'harga' => 100000,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 1,
            'status' => 'aktif',
            'sembunyi' => true,
        ]);

        // Sembunyi bukan penjualan_berhenti: ID yang masih terlanjur dikirim
        // dari halaman lama tidak boleh ditolak checkout.
        $order = app(OrderService::class)->createManual(
            $event,
            ['nama' => 'Pembeli ID Lama', 'email' => 'lama@contoh.id', 'whatsapp' => '081200000003'],
            [['ticket_type_id' => $sembunyi->id, 'jumlah' => 1]],
            'transfer',
            null,
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(9, $sembunyi->fresh()->sisa_kuota);
    }

    public function test_penanda_sembunyi_bisa_diubah_lewat_update(): void
    {
        [, $event] = $this->skenario();
        $tiket = $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket OTS',
            'harga' => 150000,
            'kuota' => 20,
            'sisa_kuota' => 20,
            'max_per_order' => 2,
            'status' => 'aktif',
            'sembunyi' => false,
        ]);

        $user = \App\Models\User::where('organizer_id', $event->organizer_id)->firstOrFail();
        $this->actingAs($user)
            ->putJson('/api/partner/ticket-types/'.$tiket->id, ['sembunyi' => true])
            ->assertOk();

        $this->assertTrue($tiket->fresh()->sembunyi, 'Penanda sembunyi harus tersimpan.');

        $this->actingAs($user)
            ->putJson('/api/partner/ticket-types/'.$tiket->id, ['sembunyi' => false])
            ->assertOk();

        $this->assertFalse($tiket->fresh()->sembunyi, 'Penanda harus bisa dimatikan lagi.');
    }

    public function test_sembunyi_dan_nonaktif_itu_dua_hal_berbeda(): void
    {
        [, $event] = $this->skenario();
        $sembunyi = $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket Undangan',
            'harga' => 0,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 1,
            'status' => 'aktif',
            'sembunyi' => true,
        ]);
        $nonaktif = $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket Berhenti',
            'harga' => 0,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 1,
            'status' => 'nonaktif',
            'sembunyi' => false,
        ]);

        $ids = collect($this->getJson('/api/events/'.$event->slug)->json('data.tiket'))
            ->pluck('id')->all();

        // Keduanya hilang dari publik, tapi hanya nonaktif yang diblokir checkout.
        $this->assertNotContains($sembunyi->id, $ids);
        $this->assertNotContains($nonaktif->id, $ids);

        $this->assertSame('aktif', $sembunyi->fresh()->status, 'Sembunyi tidak boleh mengubah status.');
        $this->assertSame('nonaktif', $nonaktif->fresh()->status);

        // Jual manual tiket sembunyi -> berhasil. Nonaktif -> ditolak.
        $order = app(OrderService::class)->createManual(
            $event,
            ['nama' => 'Tamu Undangan', 'email' => 'tamu2@contoh.id', 'whatsapp' => '081200000004'],
            [['ticket_type_id' => $sembunyi->id, 'jumlah' => 1]],
            'tunai',
            null,
        );
        $this->assertInstanceOf(Order::class, $order);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(OrderService::class)->createManual(
            $event,
            ['nama' => 'Pembeli Sibuk', 'email' => 'sibuk@contoh.id', 'whatsapp' => '081200000005'],
            [['ticket_type_id' => $nonaktif->id, 'jumlah' => 1]],
            'tunai',
            null,
        );
    }

    public function test_default_tiket_baru_tidak_sembunyi(): void
    {
        [, $event] = $this->skenario();
        $tiket = $event->ticketTypes()->create([
            'nama_tiket' => 'Tiket Normal',
            'harga' => 100000,
            'kuota' => 10,
            'sisa_kuota' => 10,
            'max_per_order' => 2,
        ]);

        $this->assertFalse($tiket->sembunyi, 'Tiket baru harus tampil di publik.');
    }
}
