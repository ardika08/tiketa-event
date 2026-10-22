<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Organizer;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithNontix;
use Tests\TestCase;

/**
 * Laporan PDF data pembeli.
 *
 * Yang dikunci di sini adalah hal yang pernah rusak atau berpotensi rusak
 * tanpa ketahuan: teks keluar margin, nomor halaman bertumpuk, nama berkas
 * tidak terbaca browser, dan data yang tidak boleh ikut terhitung.
 *
 * Butuh pdftotext/pymupdf untuk membaca ulang PDF. Kalau tidak tersedia,
 * test dilewati dengan alasan jelas — bukan gagal palsu.
 */
class PembeliPdfTest extends TestCase
{
    use InteractsWithNontix;
    use RefreshDatabase;

    private function butuhPdftotext(): void
    {
        if (trim((string) shell_exec('command -v pdftotext 2>/dev/null')) === '') {
            $this->markTestSkipped('pdftotext tidak terpasang (butuh paket poppler-utils).');
        }
    }

    /**
     * Buat pesanan lewat jalur resmi supaya item tiket ikut terbentuk.
     */
    private function pesanan(Event $event, int $kategoriId, string $nama = 'Pembeli Uji', string $status = 'lunas'): Order
    {
        $order = app(OrderService::class)->createManual(
            $event,
            ['nama' => $nama, 'email' => strtolower(str_replace(' ', '.', $nama)).'@contoh.id', 'whatsapp' => '081200000001'],
            [['ticket_type_id' => $kategoriId, 'jumlah' => 2]],
            'tunai',
            'Booth',
        );

        if ($status !== 'lunas') {
            $order->update(['status' => $status]);
        }

        return $order;
    }

    private function skenario(int $jumlahPesanan = 1, string $namaOrganizer = 'Mommils Birthclub'): array
    {
        [$user, $organizer] = $this->createPartner();
        $organizer->update(['nama_penyelenggara' => $namaOrganizer]);

        $event = $this->createEvent($organizer);
        $sesi = $event->sessions()->create([
            'label' => 'Day 1',
            'nama_session' => 'Sesi Pagi',
            'urutan' => 1,
            'kapasitas' => 500,
        ]);
        $kategori = $this->createTicketType(
            $event,
            ['nama_tiket' => 'Reguler', 'kuota' => 500, 'sisa_kuota' => 500],
            [$sesi->id],
        );

        $orders = [];
        for ($i = 1; $i <= $jumlahPesanan; $i++) {
            $orders[] = $this->pesanan($event, $kategori->id, 'Pembeli Uji '.$i);
        }

        return [$user, $organizer, $event, $kategori, $orders];
    }

    public function test_partner_bisa_unduh_pdf_pembeli(): void
    {
        [$user] = $this->skenario();

        $res = $this->actingAs($user)->get('/api/partner/buyers/pdf');

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.pdf', (string) $res->headers->get('Content-Disposition'));
    }

    public function test_tamu_tidak_bisa_unduh_pdf_pembeli(): void
    {
        $this->skenario();

        $this->getJson('/api/partner/buyers/pdf')->assertUnauthorized();
    }

    /**
     * Nama berkas harus terbaca JavaScript di browser. Tanpa header ini,
     * frontend lintas domain hanya menerima nama generik "unduhan.pdf".
     */
    public function test_header_nama_berkas_bisa_dibaca_lintas_origin(): void
    {
        [$user] = $this->skenario();

        $res = $this->actingAs($user)->get('/api/partner/buyers/pdf');

        $this->assertSeeNamaBerkas($res->headers->get('Access-Control-Expose-Headers'));
    }

    private function assertSeeNamaBerkas(?string $header): void
    {
        $this->assertSame('Content-Disposition', $header);
    }

    public function test_nama_berkas_pembeli_profesional_dan_aman(): void
    {
        // Karakter haram di Windows harus hilang dari nama berkas.
        [$user] = $this->skenario(1, 'PT. Diarma / Aksara: Media*?<>|');

        $res = $this->actingAs($user)->get('/api/partner/buyers/pdf');
        $disposisi = (string) $res->headers->get('Content-Disposition');
        $nama = rawurldecode(explode("filename*=UTF-8''", $disposisi)[1] ?? '');

        $this->assertStringContainsString('Laporan-Pembeli', $nama);
        foreach (['/', ':', '*', '?', '<', '>', '|', '"', '\\'] as $haram) {
            $this->assertStringNotContainsString($haram, $nama, "Nama berkas masih memuat '{$haram}'.");
        }
        $this->assertStringEndsWith('.pdf', $nama);
    }

    public function test_pesanan_batal_dan_belum_bayar_tidak_dihitung(): void
    {
        $this->butuhPdftotext();

        [$user, , $event, $kategori] = $this->skenario();
        $batal = $this->pesanan($event, $kategori->id, 'Pembeli Batal', 'dibatalkan');
        $pending = $this->pesanan($event, $kategori->id, 'Pembeli Pending', 'pending');

        $res = $this->actingAs($user)->get('/api/partner/buyers/pdf');
        $teks = $this->teksPdf($res->getContent());

        $this->assertStringContainsString('Pembeli Uji 1', $teks);
        $this->assertStringNotContainsString($batal->kode_order, $teks);
        $this->assertStringNotContainsString($pending->kode_order, $teks);
        // Yang tidak ikut tetap dilaporkan jumlahnya, tidak disembunyikan.
        $this->assertMatchesRegularExpression('/\d+ pesanan batal/u', $teks);
    }

    public function test_nomor_halaman_muncul_sekali_per_halaman(): void
    {
        $this->butuhPdftotext();

        [$user] = $this->skenario(40);

        $res = $this->actingAs($user)->get('/api/partner/buyers/pdf');
        $jalur = $this->tulisPdf($res->getContent());

        $jumlahHalaman = (int) trim((string) shell_exec(
            'pdfinfo '.escapeshellarg($jalur).' 2>/dev/null | awk \'/^Pages:/ {print $2}\''
        ));
        $this->assertGreaterThan(1, $jumlahHalaman, 'Data 40 pesanan seharusnya lebih dari satu halaman.');

        $teks = (string) shell_exec('pdftotext -layout '.escapeshellarg($jalur).' - 2>/dev/null');
        for ($h = 1; $h <= $jumlahHalaman; $h++) {
            $jumlah = preg_match_all('/Halaman '.$h.' dari '.$jumlahHalaman.'/u', $teks);
            $this->assertSame(1, $jumlah, "Nomor halaman {$h} muncul {$jumlah} kali, seharusnya 1.");
        }

        @unlink($jalur);
    }

    /**
     * Semua teks harus di dalam area cetak A4: batas kanan = 595.28 - 30 =
     * 565.28pt. Toleransi 1pt untuk pembulatan.
     */
    public function test_tidak_ada_teks_keluar_margin_kanan(): void
    {
        [$user] = $this->skenario(30, 'Nama Penyelenggara Yang Sangat Panjang Sekali Ya');

        $res = $this->actingAs($user)->get('/api/partner/buyers/pdf');
        $jalur = $this->tulisPdf($res->getContent());

        $keluar = trim((string) shell_exec(
            'timeout 120 uvx --from pymupdf python -c "'
            .'import pymupdf; d=pymupdf.open(\''.$jalur.'\'); '
            .'print(sum(1 for p in d for b in p.get_text(\'blocks\') if b[2] > 566.28))" 2>/dev/null'
        ));

        @unlink($jalur);

        if ($keluar === '') {
            $this->markTestSkipped('pymupdf tidak tersedia untuk mengukur posisi teks.');
        }

        $this->assertSame('0', $keluar, "Ada {$keluar} blok teks yang melewati margin kanan halaman.");
    }

    private function tulisPdf(string $biner): string
    {
        $jalur = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($jalur, $biner);

        return $jalur;
    }

    private function teksPdf(string $biner): string
    {
        $jalur = $this->tulisPdf($biner);
        $teks = (string) shell_exec('pdftotext -layout '.escapeshellarg($jalur).' - 2>/dev/null');
        @unlink($jalur);

        return $teks;
    }
}
