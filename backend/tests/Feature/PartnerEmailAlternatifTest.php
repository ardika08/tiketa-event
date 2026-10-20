<?php

namespace Tests\Feature;

use App\Jobs\SendAlternativeTicketEmail;
use App\Models\EmailLog;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Jalur darurat "kirim e-ticket ke email lain".
 *
 * Kasus nyata: pembeli dengan alamat @outlook.com tidak menerima email sama
 * sekali (tidak di inbox, tidak di Junk). Tombol kirim ulang biasa tidak
 * menolong karena tetap ke alamat yang sama, jadi partner perlu bisa mengirim
 * SALINAN tiket ke alamat lain — tanpa mengubah email pesanan.
 */
class PartnerEmailAlternatifTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.mayar.callback_token', null);
        config()->set('nontix.mailketing.mode', 'fake');
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Event, 2: \App\Models\TicketType} */
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

        return [$user, $event, $kategori];
    }

    private function buatPesanan($event, $ticketType, string $email = 'natalia@outlook.com'): Order
    {
        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Natalia',
            'email' => $email,
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $ticketType->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        $this->getJson("/api/payments/{$kode}/fake")->assertOk();

        return Order::where('kode_order', $kode)->firstOrFail();
    }

    public function test_kirim_ke_email_lain_mencatat_jejak_tanpa_mengubah_email_pesanan(): void
    {
        [$user, $event, $kategori] = $this->skenario();
        $order = $this->buatPesanan($event, $kategori);

        Sanctum::actingAs($user);

        $this->postJson("/api/partner/orders/{$order->id}/email-alternatif", [
            'email' => 'natalia.kerja@gmail.com',
            'konfirmasi' => true,
        ])->assertOk()->assertJsonPath('tujuan', 'natalia.kerja@gmail.com');

        // Email pesanan TIDAK berubah — tiket tetap milik pembeli asli.
        $this->assertSame('natalia@outlook.com', $order->fresh()->email);

        $log = EmailLog::where('order_id', $order->id)->manual()->firstOrFail();
        $this->assertSame('natalia.kerja@gmail.com', $log->tujuan);
        $this->assertSame('natalia@outlook.com', $log->email_pesanan);
        $this->assertSame(EmailLog::STATUS_TERKIRIM, $log->status);
        $this->assertSame($user->id, $log->user_id);
        $this->assertNotEmpty($log->message_id);
    }

    public function test_tanpa_centang_konfirmasi_ditolak(): void
    {
        Queue::fake();
        [$user, $event, $kategori] = $this->skenario();
        $order = $this->buatPesanan($event, $kategori);

        Sanctum::actingAs($user);

        $this->postJson("/api/partner/orders/{$order->id}/email-alternatif", [
            'email' => 'lain@gmail.com',
        ])->assertStatus(422)->assertJsonValidationErrors('konfirmasi');

        Queue::assertNotPushed(SendAlternativeTicketEmail::class);
    }

    public function test_alamat_yang_sama_dengan_email_pesanan_ditolak(): void
    {
        Queue::fake();
        [$user, $event, $kategori] = $this->skenario();
        $order = $this->buatPesanan($event, $kategori);

        Sanctum::actingAs($user);

        $this->postJson("/api/partner/orders/{$order->id}/email-alternatif", [
            'email' => 'natalia@outlook.com',
            'konfirmasi' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_domain_salah_ketik_ditolak_dengan_saran(): void
    {
        Queue::fake();
        [$user, $event, $kategori] = $this->skenario();
        $order = $this->buatPesanan($event, $kategori);

        Sanctum::actingAs($user);

        $respon = $this->postJson("/api/partner/orders/{$order->id}/email-alternatif", [
            'email' => 'natalia@gmial.com',
            'konfirmasi' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // Pesan menyebut alamat yang benar supaya partner tinggal membetulkan.
        $this->assertStringContainsString('natalia@gmail.com', (string) $respon->json('errors.email.0'));
        Queue::assertNotPushed(SendAlternativeTicketEmail::class);
    }

    public function test_maksimal_tiga_kali_sehari_per_pesanan(): void
    {
        Queue::fake();
        [$user, $event, $kategori] = $this->skenario();
        $order = $this->buatPesanan($event, $kategori);

        foreach ([1, 2, 3] as $i) {
            EmailLog::create([
                'order_id' => $order->id,
                'event_id' => $event->id,
                'jenis' => EmailLog::JENIS_MANUAL,
                'tujuan' => "cadangan{$i}@gmail.com",
                'email_pesanan' => $order->email,
                'status' => EmailLog::STATUS_TERKIRIM,
            ]);
        }

        Sanctum::actingAs($user);

        $this->postJson("/api/partner/orders/{$order->id}/email-alternatif", [
            'email' => 'cadangan4@gmail.com',
            'konfirmasi' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        Queue::assertNotPushed(SendAlternativeTicketEmail::class);
    }

    public function test_pesanan_partner_lain_tidak_bisa_dikirimi(): void
    {
        Queue::fake();
        [, $event, $kategori] = $this->skenario();
        $order = $this->buatPesanan($event, $kategori);

        [$userLain] = $this->createPartner();
        Sanctum::actingAs($userLain);

        $this->postJson("/api/partner/orders/{$order->id}/email-alternatif", [
            'email' => 'lain@gmail.com',
            'konfirmasi' => true,
        ])->assertStatus(404);

        Queue::assertNotPushed(SendAlternativeTicketEmail::class);
    }

    public function test_riwayat_email_memuat_invoice_dan_kirim_manual(): void
    {
        [$user, $event, $kategori] = $this->skenario();
        $order = $this->buatPesanan($event, $kategori);

        Sanctum::actingAs($user);

        $this->postJson("/api/partner/orders/{$order->id}/email-alternatif", [
            'email' => 'natalia.kerja@gmail.com',
            'konfirmasi' => true,
        ])->assertOk();

        $riwayat = $this->getJson("/api/partner/orders/{$order->id}/emails")
            ->assertOk()
            ->assertJsonPath('email_pesanan', 'natalia@outlook.com')
            ->json('data');

        $jenis = array_column($riwayat, 'jenis');
        $this->assertContains(EmailLog::JENIS_INVOICE, $jenis);
        $this->assertContains(EmailLog::JENIS_MANUAL, $jenis);

        $manual = collect($riwayat)->firstWhere('jenis', EmailLog::JENIS_MANUAL);
        $this->assertSame('natalia.kerja@gmail.com', $manual['tujuan']);
        $this->assertSame($user->nama, $manual['oleh']);
    }

    public function test_email_pesanan_belum_lunas_ditolak(): void
    {
        Queue::fake();
        [$user, $event, $kategori] = $this->skenario();

        $kode = $this->postJson('/api/orders', [
            'event_id' => $event->id,
            'nama' => 'Natalia',
            'email' => 'natalia@outlook.com',
            'whatsapp' => '08123456789',
            'items' => [['ticket_type_id' => $kategori->id, 'jumlah' => 1]],
        ])->json('data.kode_order');

        $order = Order::where('kode_order', $kode)->firstOrFail();

        Sanctum::actingAs($user);

        $this->postJson("/api/partner/orders/{$order->id}/email-alternatif", [
            'email' => 'lain@gmail.com',
            'konfirmasi' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('order');
    }
}
