<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use App\Models\TicketType;
use App\Services\OrderService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Laporan keuangan PDF.
 *
 * Yang dikunci di sini bukan "file PDF berhasil dibuat" — itu terlalu lemah.
 * Yang penting: angkanya benar, pencairan yang ditolak tidak mengurangi saldo,
 * order belum lunas tidak ikut dihitung, dan tamu tidak bisa mengunduh.
 */
class KeuanganPdfTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithNontix;

    /** @return array{0: \App\Models\User, 1: \App\Models\Organizer, 2: \App\Models\Event, 3: \App\Models\TicketType} */
    private function skenario(): array
    {
        [$user, $organizer] = $this->createPartner();
        $event = $this->createEvent($organizer);

        $sesi = $event->sessions()->create([
            'label' => 'Day 1',
            'nama_session' => 'Sesi Pagi',
            'urutan' => 1,
            'kapasitas' => 100,
        ]);

        $kategori = $this->createTicketType(
            $event,
            ['nama_tiket' => 'Reguler', 'kuota' => 50, 'sisa_kuota' => 50],
            [$sesi->id],
        );

        return [$user, $organizer, $event, $kategori];
    }

    public function test_partner_bisa_unduh_pdf_dan_angkanya_cocok_dengan_laporan(): void
    {
        [$user, $organizer, $event, $kategori] = $this->skenario();

        $order = app(OrderService::class)->createManual(
            $event,
            ['nama' => 'Amelia Putri', 'email' => 'kelas2bamelia@gmail.com', 'whatsapp' => '081200000001'],
            [['ticket_type_id' => $kategori->id, 'jumlah' => 3]],
            'tunai',
            'Booth',
            $user->id,
        );
        $order->forceFill(['paid_at' => now()->subDays(2)])->save();

        Payout::create([
            'organizer_id' => $organizer->id,
            'jumlah' => 100000,
            'status' => 'selesai',
            'nama_bank' => 'BCA',
            'nomor_rekening' => '1234567890',
            'nama_rekening' => 'Diamenggundang EO',
            'selesai_at' => now()->subDay(),
        ]);

        // Pencairan ditolak: dananya tidak pernah keluar, jadi tidak boleh
        // mengurangi saldo di laporan.
        Payout::create([
            'organizer_id' => $organizer->id,
            'jumlah' => 500000,
            'status' => 'ditolak',
            'nama_bank' => 'BCA',
            'nomor_rekening' => '1234567890',
            'nama_rekening' => 'Diamenggundang EO',
            'catatan_admin' => 'Rekening tidak valid',
        ]);

        Sanctum::actingAs($user);
        $respons = $this->get('/api/partner/finance/pdf');

        $respons->assertOk();
        $respons->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment;', $respons->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.pdf', $respons->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $respons->getContent(), 'Respons bukan berkas PDF.');

        // Angka di laporan harus sama dengan halaman Keuangan.
        $summary = app(ReportService::class)->partnerSummary($organizer->id);
        $this->assertSame(3, (int) $summary['tiket_terjual']);
        $this->assertSame(300000.0, (float) $summary['pendapatan_kotor']);
        $this->assertSame(6000.0, (float) $summary['biaya_layanan']);
        $this->assertSame(294000.0, (float) $summary['pendapatan_bersih']);

        // Saldo akhir mutasi = netto - pencairan yang tidak ditolak (100.000).
        $this->assertSame(194000.0, $this->saldoAkhir($organizer->id));
    }

    public function test_pencairan_ditolak_tidak_mengurangi_saldo(): void
    {
        [, $organizer] = $this->skenario();

        Payout::create([
            'organizer_id' => $organizer->id,
            'jumlah' => 750000,
            'status' => 'ditolak',
            'nama_bank' => 'BCA',
            'nomor_rekening' => '1234567890',
            'nama_rekening' => 'Diamenggundang EO',
        ]);

        $this->assertSame(0.0, $this->saldoAkhir($organizer->id));
    }

    public function test_order_belum_lunas_tidak_masuk_laporan(): void
    {
        [, $organizer, $event] = $this->skenario();

        Order::create([
            'kode_order' => 'NTX-PENDING',
            'event_id' => $event->id,
            'nama_pembeli' => 'Belum Bayar',
            'email' => 'belum@uji.com',
            'whatsapp' => '081200000002',
            'status' => OrderStatus::PENDING,
            'total_harga' => 500000,
            'biaya_layanan' => 2000,
            'expires_at' => now()->addMinutes(30),
        ]);

        $finance = app(ReportService::class)->partnerFinance($organizer->id);

        $this->assertCount(0, $finance['transaksi']);
        $this->assertSame(0.0, $this->saldoAkhir($organizer->id));
    }

    public function test_tamu_tidak_bisa_unduh_pdf(): void
    {
        $this->getJson('/api/partner/finance/pdf')->assertUnauthorized();
    }

    /**
     * Footer hak cipta digambar lewat callback DomPDF, bukan HTML, jadi tidak
     * akan terlihat dari test HTTP biasa. Yang diuji: teksnya benar, tahunnya
     * mengikuti tahun berjalan, dan entitas hukumnya persis sama dengan footer
     * di halaman publik.
     */
    public function test_footer_hak_cipta_sesuai_entitas_hukum(): void
    {
        $service = app(\App\Services\KeuanganPdfService::class);
        $teks = $service->teksHakCipta();

        $this->assertSame(
            '© '.date('Y').' Nontix — PT. DIARMA AKSARA MEDIA. Seluruh hak cipta dilindungi.',
            $teks,
        );
        $this->assertStringContainsString('PT. DIARMA AKSARA MEDIA', $teks);
        $this->assertStringContainsString('Seluruh hak cipta dilindungi', $teks);

        // Badan PDF tetap terbentuk dan tetap berkas PDF sah.
        [$user] = $this->skenario();
        Sanctum::actingAs($user);
        $respons = $this->get('/api/partner/finance/pdf');

        $respons->assertOk();
        $this->assertStringStartsWith('%PDF-', $respons->getContent());
    }

    /**
     * Saldo akhir dihitung lewat service, bukan dibaca dari teks PDF —
     * teks PDF tidak andal untuk asersi angka.
     */
    private function saldoAkhir(int $organizerId): float
    {
        $service = app(\App\Services\KeuanganPdfService::class);
        $metode = (new \ReflectionClass($service))->getMethod('susunMutasi');
        $metode->setAccessible(true);

        $finance = app(ReportService::class)->partnerFinance($organizerId);
        [, $saldo] = $metode->invoke($service, collect($finance['transaksi']), $finance['payout']['data'] ?? []);

        return (float) $saldo;
    }
}
