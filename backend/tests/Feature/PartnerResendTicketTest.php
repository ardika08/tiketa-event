<?php

namespace Tests\Feature;

use App\Jobs\SendResendTicketEmail;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Kirim ulang e-ticket.
 *
 * Dua sisi yang diuji:
 *  1. Endpoint partner baru (/api/partner/orders/{order}/resend) — tombol
 *     "Kirim Ulang" di /partner/pembeli. Wajib hanya boleh menyentuh pesanan
 *     milik partner yang login, dan hanya pesanan lunas.
 *  2. Rate limit endpoint publik (/api/tickets/resend) — dulu tanpa batas,
 *     sehingga bisa dipakai membanjiri inbox pembeli.
 */
class PartnerResendTicketTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.mayar.callback_token', null);
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Organizer, 2: \App\Models\Event, 3: \App\Models\TicketType} */
    private function skenario(): array
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $event->sessions()->create([
            'label' => 'Day 1',
            'nama_session' => 'Pembuka',
            'urutan' => 1,
            'kapasitas' => 100,
        ]);
        $kategori = $this->createTicketType($event, ['nama_tiket' => 'Reguler'], [$sesi->id]);

        return [$user, $organizer, $event, $kategori];
    }

    private function buatPesanan($event, $ticketType, bool $lunas = true, string $email = 'budi@mail.com'): Order
    {
        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Budi',
            'email' => $email,
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $ticketType->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        if ($lunas) {
            $this->getJson("/api/payments/{$kode}/fake")->assertOk();
        }

        return Order::where('kode_order', $kode)->firstOrFail();
    }

    public function test_partner_bisa_kirim_ulang_tiket_pesanan_lunas_miliknya(): void
    {
        Queue::fake();
        [$user, , $event, $kategori] = $this->skenario();
        $order = $this->buatPesanan($event, $kategori);

        Sanctum::actingAs($user);

        $this->postJson("/api/partner/orders/{$order->id}/resend")
            ->assertOk()
            ->assertJsonPath('message', 'E-ticket dikirim ulang ke budi@mail.com.');

        Queue::assertPushed(SendResendTicketEmail::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_pesanan_milik_partner_lain_tidak_bisa_dikirim_ulang(): void
    {
        Queue::fake();
        [, , $event, $kategori] = $this->skenario();
        $orderPartnerLain = $this->buatPesanan($event, $kategori);

        // Partner kedua, dengan event sendiri.
        [$userLain] = $this->createPartner();
        Sanctum::actingAs($userLain);

        // 404 (bukan 403) supaya keberadaan pesanan tidak bocor.
        $this->postJson("/api/partner/orders/{$orderPartnerLain->id}/resend")
            ->assertStatus(404);

        Queue::assertNotPushed(SendResendTicketEmail::class);
    }

    public function test_pesanan_belum_lunas_ditolak(): void
    {
        Queue::fake();
        [$user, , $event, $kategori] = $this->skenario();
        $order = $this->buatPesanan($event, $kategori, lunas: false);

        Sanctum::actingAs($user);

        $this->postJson("/api/partner/orders/{$order->id}/resend")
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');

        Queue::assertNotPushed(SendResendTicketEmail::class);
    }

    public function test_tamu_tidak_bisa_akses_endpoint_partner(): void
    {
        Queue::fake();
        [, , $event, $kategori] = $this->skenario();
        $order = $this->buatPesanan($event, $kategori);

        $this->postJson("/api/partner/orders/{$order->id}/resend")->assertStatus(401);

        Queue::assertNotPushed(SendResendTicketEmail::class);
    }

    public function test_endpoint_publik_resend_dibatasi_per_ip(): void
    {
        Queue::fake();
        [, , $event, $kategori] = $this->skenario();
        $order = $this->buatPesanan($event, $kategori);

        // IP khusus supaya penghitung throttle tidak bercampur dengan tes lain.
        $ip = ['REMOTE_ADDR' => '203.0.113.7'];

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables($ip)
                ->postJson('/api/tickets/resend', ['kode_order' => $order->kode_order])
                ->assertOk();
        }

        $this->withServerVariables($ip)
            ->postJson('/api/tickets/resend', ['kode_order' => $order->kode_order])
            ->assertStatus(429);
    }
}
