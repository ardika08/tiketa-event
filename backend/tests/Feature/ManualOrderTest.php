<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Order;
use App\Models\TicketPass;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Penjualan tiket manual (OTS / tunai / transfer).
 *
 * Yang dijaga di sini: tiket manual harus SETARA dengan tiket online —
 * langsung lunas, punya QR yang sah dan bisa di-scan, memotong kuota, dan
 * tetap dikenakan biaya layanan platform (2.000/tiket) karena pengiriman
 * e-ticket memakai kuota email platform.
 */
class ManualOrderTest extends TestCase
{
    use InteractsWithNontix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nontix.mayar.mode', 'fake');
        config()->set('nontix.mayar.callback_token', null);
        config()->set('nontix.mailketing.mode', 'fake');
        config()->set('nontix.service_fee', 2000);
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Event, 2: \App\Models\TicketType} */
    private function skenario(int $kuota = 20): array
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);
        $sesi = $event->sessions()->create([
            'label' => 'Day 1',
            'nama_session' => 'Pembuka',
            'urutan' => 1,
            'kapasitas' => 100,
        ]);
        $kategori = $this->createTicketType(
            $event,
            ['nama_tiket' => 'Reguler', 'kuota' => $kuota, 'sisa_kuota' => $kuota],
            [$sesi->id],
        );

        return [$user, $event, $kategori];
    }

    private function payloadManual($event, $kategori, array $override = []): array
    {
        return array_merge([
            'event_id' => $event->id,
            'nama' => 'Pembeli OTS',
            'email' => 'ots@mail.com',
            'whatsapp' => '081200000000',
            'metode_bayar' => 'tunai',
            'catatan' => 'dijual di pintu masuk',
            'items' => [['ticket_type_id' => $kategori->id, 'jumlah' => 2]],
        ], $override);
    }

    public function test_tiket_manual_langsung_lunas_dan_punya_qr(): void
    {
        [$user, $event, $kategori] = $this->skenario();

        Sanctum::actingAs($user);

        $respon = $this->postJson('/api/partner/manual-orders', $this->payloadManual($event, $kategori))
            ->assertCreated()
            ->assertJsonPath('data.sumber', Order::SUMBER_MANUAL)
            ->assertJsonPath('data.jumlah_tiket', 2);

        $order = Order::where('kode_order', $respon->json('data.kode_order'))->firstOrFail();

        $this->assertTrue($order->isPaid());
        $this->assertTrue($order->isManual());
        $this->assertNotNull($order->paid_at);
        $this->assertSame('tunai', $order->metode_bayar);
        $this->assertSame($user->id, $order->created_by);

        // QR benar-benar dibuat (bukan sekadar order lunas tanpa tiket).
        $this->assertSame(2, $order->tickets()->count());
        $qr = TicketPass::whereIn('ticket_id', $order->tickets()->pluck('id'))->get();
        $this->assertCount(2, $qr);
        $this->assertNotEmpty($qr->first()->kode_qr);

        // Kuota terpotong.
        $this->assertSame(18, (int) $kategori->fresh()->sisa_kuota);
    }

    public function test_biaya_layanan_tetap_dipungut_per_tiket(): void
    {
        [$user, $event, $kategori] = $this->skenario();

        Sanctum::actingAs($user);

        $respon = $this->postJson('/api/partner/manual-orders', $this->payloadManual($event, $kategori, [
            'items' => [['ticket_type_id' => $kategori->id, 'jumlah' => 3]],
        ]))->assertCreated();

        $order = Order::where('kode_order', $respon->json('data.kode_order'))->firstOrFail();

        // 2.000 x 3 tiket — dipungut karena e-ticket tetap dikirim lewat platform.
        $this->assertSame(6000.0, (float) $order->biaya_layanan);
    }

    public function test_biaya_layanan_memotong_pendapatan_partner(): void
    {
        [$user, $event, $kategori] = $this->skenario();

        Sanctum::actingAs($user);

        $harga = (float) $kategori->harga;
        $this->postJson('/api/partner/manual-orders', $this->payloadManual($event, $kategori))->assertCreated();

        $summary = app(ReportService::class)->partnerSummary($event->organizer_id);

        $this->assertSame($harga * 2, (float) $summary['pendapatan_kotor']);
        $this->assertSame(4000.0, (float) $summary['biaya_layanan']);
        $this->assertSame(($harga * 2) - 4000, (float) $summary['pendapatan_bersih']);
    }

    public function test_email_tiket_tercatat_di_riwayat_pengiriman(): void
    {
        [$user, $event, $kategori] = $this->skenario();

        Sanctum::actingAs($user);

        $respon = $this->postJson('/api/partner/manual-orders', $this->payloadManual($event, $kategori))->assertCreated();
        $order = Order::where('kode_order', $respon->json('data.kode_order'))->firstOrFail();

        $log = EmailLog::where('order_id', $order->id)
            ->where('jenis', EmailLog::JENIS_MANUAL_ORDER)
            ->firstOrFail();

        $this->assertSame('ots@mail.com', $log->tujuan);
        $this->assertSame(EmailLog::STATUS_TERKIRIM, $log->status);
    }

    public function test_tiket_manual_bisa_discan_di_gerbang(): void
    {
        [$user, $event, $kategori] = $this->skenario();

        Sanctum::actingAs($user);

        $respon = $this->postJson('/api/partner/manual-orders', $this->payloadManual($event, $kategori))->assertCreated();
        $order = Order::where('kode_order', $respon->json('data.kode_order'))->firstOrFail();
        $kodeQr = TicketPass::whereIn('ticket_id', $order->tickets()->pluck('id'))->first()->kode_qr;

        // Bukti bahwa tiket manual memakai jalur QR yang sama dengan tiket online.
        $this->postJson('/api/partner/scan', [
            'event_id' => $event->id,
            'kode_qr' => $kodeQr,
        ])->assertOk()->assertJsonPath('status', 'valid');
    }

    public function test_kuota_tidak_cukup_ditolak(): void
    {
        [$user, $event, $kategori] = $this->skenario(kuota: 1);

        Sanctum::actingAs($user);

        $this->postJson('/api/partner/manual-orders', $this->payloadManual($event, $kategori, [
            'items' => [['ticket_type_id' => $kategori->id, 'jumlah' => 2]],
        ]))->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame(0, Order::where('sumber', Order::SUMBER_MANUAL)->count());
        $this->assertSame(1, (int) $kategori->fresh()->sisa_kuota);
    }

    public function test_event_partner_lain_tidak_bisa_dijual_manual(): void
    {
        [$user, $event, $kategori] = $this->skenario();

        // Partner berbeda menjual tiket event orang lain.
        [$userLain] = $this->createPartner();
        Sanctum::actingAs($userLain);

        $this->postJson('/api/partner/manual-orders', $this->payloadManual($event, $kategori))
            ->assertStatus(404);
    }

    public function test_validasi_wajib_email_dan_metode(): void
    {
        [$user, $event, $kategori] = $this->skenario();

        Sanctum::actingAs($user);

        $this->postJson('/api/partner/manual-orders', [
            'event_id' => $event->id,
            'nama' => 'Tanpa Email',
            'whatsapp' => '0812',
            'metode_bayar' => 'bitcoin',
            'items' => [['ticket_type_id' => $kategori->id, 'jumlah' => 1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'metode_bayar']);
    }

    public function test_email_manual_tidak_mengklaim_pembayaran_berhasil(): void
    {
        [$user, $event, $kategori] = $this->skenario();

        Sanctum::actingAs($user);

        $respon = $this->postJson('/api/partner/manual-orders', $this->payloadManual($event, $kategori))->assertCreated();
        $order = Order::where('kode_order', $respon->json('data.kode_order'))->firstOrFail();

        $service = app(\App\Services\OrderService::class);
        $mail = new \App\Mail\OrderPaidMail($order->fresh($service->relations()));
        $mail->build();

        // Subjek jujur: bukan invoice, karena tidak ada tagihan gateway.
        $this->assertStringStartsWith('E-Ticket '.$order->kode_order, $mail->subject);
        $this->assertStringNotContainsString('Invoice', $mail->subject);

        $html = $mail->render();
        $kodeQr = \App\Models\TicketPass::whereIn('ticket_id', $order->tickets()->pluck('id'))->first()->kode_qr;

        // Isi: e-ticket + QR benar-benar ada, tanpa klaim pembayaran gateway.
        $this->assertStringContainsString($kodeQr, $html);
        $this->assertStringNotContainsString('Pembayaran Berhasil', $html);
        $this->assertStringContainsString('diterima langsung oleh panitia', $html);
    }

    public function test_email_online_tetap_memakai_template_invoice(): void
    {
        [$user, $event, $kategori] = $this->skenario();

        $service = app(\App\Services\OrderService::class);
        $order = $service->create(
            $event,
            ['nama' => 'Pembeli Online', 'email' => 'online@mail.com', 'whatsapp' => '081211112222'],
            [['ticket_type_id' => $kategori->id, 'jumlah' => 1]],
        );
        $service->markPaid($order, null, 'qris');
        $order = $order->fresh($service->relations());

        $mail = new \App\Mail\OrderPaidMail($order);
        $mail->build();

        $this->assertStringStartsWith('Invoice & E-Ticket', $mail->subject);
        $this->assertStringContainsString('Pembayaran Berhasil', $mail->render());
    }
}
