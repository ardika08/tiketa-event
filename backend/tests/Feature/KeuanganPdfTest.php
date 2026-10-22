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
     * Nama berkas unduhan harus deskriptif dan aman di semua sistem operasi.
     */
    public function test_nama_berkas_pdf_profesional_dan_aman(): void
    {
        [$user, $organizer] = $this->skenario();
        $organizer->update(['nama_penyelenggara' => 'Mommils Birthclub']);

        Sanctum::actingAs($user);
        $respons = $this->get('/api/partner/finance/pdf');
        $respons->assertOk();

        $disposisi = $respons->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment;', $disposisi);
        $this->assertStringContainsString(
            'Nontix_Laporan-Keuangan_Mommils-Birthclub_'.now()->format('Y-m').'.pdf',
            $disposisi,
        );

        // Tanpa ini, browser menyembunyikan nama berkas pada request lintas
        // origin dan frontend terpaksa memakai nama generik.
        $this->assertSame(
            'Content-Disposition',
            $respons->headers->get('Access-Control-Expose-Headers'),
        );
    }

    /**
     * Karakter yang haram di nama berkas (Windows) tidak boleh lolos.
     */
    public function test_nama_berkas_membuang_karakter_terlarang(): void
    {
        [$user, $organizer] = $this->skenario();
        $organizer->update(['nama_penyelenggara' => 'PT. Diarma / Aksara: Media*?<>|']);

        Sanctum::actingAs($user);
        $disposisi = $this->get('/api/partner/finance/pdf')->headers->get('Content-Disposition');

        $nama = $this->namaDariDisposisi($disposisi);

        foreach (['/', '\\', ':', '*', '?', '"', '<', '>', '|'] as $haram) {
            $this->assertStringNotContainsString($haram, $nama, "karakter '{$haram}' tidak boleh ada di nama berkas");
        }
        $this->assertStringStartsWith('Nontix_Laporan-Keuangan_', $nama);
        $this->assertStringEndsWith('.pdf', $nama);
        $this->assertLessThan(120, strlen($nama));
    }

    /**
     * Ambil nama berkas dari header Content-Disposition (utamakan filename*).
     */
    private function namaDariDisposisi(string $disposisi): string
    {
        if (preg_match("/filename\*\s*=\s*(?:UTF-8'')?([^;]+)/i", $disposisi, $m)) {
            return rawurldecode(trim($m[1], '"'));
        }

        preg_match('/filename\s*=\s*"?([^";]+)"?/i', $disposisi, $m);

        return trim($m[1] ?? '');
    }

    /**
     * Nomor halaman harus muncul SEKALI per halaman dan tidak keluar margin.
     *
     * Regresi yang pernah terjadi: page_script() + callback berisi page_text()
     * menggambar nomor 1..N di SETIAP halaman, bertumpuk di satu titik sampai
     * tampak seperti kotak hitam. Juga posisi x tetap membuat teks meluber
     * keluar halaman saat jumlah halaman dua digit.
     */
    public function test_nomor_halaman_sekali_per_halaman_dan_di_dalam_margin(): void
    {
        [$user] = $this->skenario();

        for ($i = 1; $i <= 25; $i++) {
            Order::create([
                'kode_order' => 'NTX-UJI'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'event_id' => Event::first()->id,
                'user_id' => null,
                'nama_pembeli' => 'Pembeli '.$i,
                'email' => "p{$i}@mail.com",
                'whatsapp' => '081200000'.str_pad((string) $i, 2, '0'),
                'total' => 99000,
                'biaya_layanan' => 2000,
                'status' => OrderStatus::PAID,
                'sumber' => 'manual',
                'created_at' => now()->subDays($i),
            ]);
        }

        Sanctum::actingAs($user);
        $respons = $this->get('/api/partner/finance/pdf');
        $respons->assertOk();

        $teks = $this->teksPdf($respons->getContent());

        $this->assertGreaterThanOrEqual(2, $teks['halaman'], 'laporan harus lebih dari satu halaman');

        for ($h = 1; $h <= $teks['halaman']; $h++) {
            $nomor = array_filter(
                $teks['perHalaman'][$h],
                fn (string $t) => preg_match('/Halaman \d+ dari \d+/', $t) === 1,
            );

            $this->assertCount(
                1, $nomor,
                "halaman {$h} harus punya tepat satu nomor halaman, bukan ".count($nomor),
            );
            $this->assertStringContainsString("Halaman {$h} dari", reset($nomor));
        }

        $this->assertLessThanOrEqual(
            $teks['lebar'] - 25,
            $teks['x1Terbesar'],
            'nomor halaman tidak boleh melewati margin kanan',
        );
    }

    /**
     * Baca ulang PDF hasil render untuk memeriksa teks per halaman.
     * Dipakai test — bukan untuk produksi.
     *
     * @return array{halaman: int, perHalaman: array<int, array<int, string>>, x1Terbesar: float, lebar: float}
     */
    private function teksPdf(string $biner): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($tmp, $biner);

        $hasil = ['halaman' => 0, 'perHalaman' => [], 'x1Terbesar' => 0.0, 'lebar' => 0.0];

        // pdftotext -layout memberi teks per halaman tanpa dependensi PHP baru;
        // kalau tidak tersedia, test ini dilewati (bukan gagal) agar CI tanpa
        // poppler tidak merah hanya karena alat bantu.
        $ada = shell_exec('command -v pdftotext 2>/dev/null');
        if (! $ada) {
            @unlink($tmp);
            $this->markTestSkipped('pdftotext tidak tersedia untuk membaca ulang PDF.');
        }

        $keluar = shell_exec('pdftotext -layout '.escapeshellarg($tmp).' - 2>/dev/null') ?? '';
        $halamanTeks = preg_split("/\f/", $keluar) ?: [];

        $hasil['halaman'] = count(array_filter($halamanTeks, fn ($t) => trim($t) !== ''));
        foreach ($halamanTeks as $i => $t) {
            if (trim($t) === '') {
                continue;
            }
            $hasil['perHalaman'][$i + 1] = array_map('trim', explode("\n", $t));
        }

        // Lebar halaman A4 dalam pt (595.28). Dipakai untuk batas margin.
        $hasil['lebar'] = 595.28;
        $hasil['x1Terbesar'] = $this->x1NomorHalaman($tmp);

        @unlink($tmp);

        return $hasil;
    }

    /**
     * Ambil posisi x paling kanan dari teks nomor halaman.
     */
    private function x1NomorHalaman(string $berkas): float
    {
        $ada = shell_exec('command -v pdftotext 2>/dev/null');
        if (! $ada) {
            return 0.0;
        }

        // pdftotext -bbox melaporkan kotak setiap kata: <word xMin= yMin= xMax= yMax=>
        $xml = shell_exec('pdftotext -bbox '.escapeshellarg($berkas).' - 2>/dev/null') ?? '';
        $maks = 0.0;

        if (preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)"/', $xml, $m) === false) {
            return 0.0;
        }

        // Kata yang berada di pita footer (y > 780pt).
        foreach ($m[2] as $i => $y) {
            if ((float) $y > 780) {
                $maks = max($maks, (float) $m[3][$i]);
            }
        }

        return $maks;
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
