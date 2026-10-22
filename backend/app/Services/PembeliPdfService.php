<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Organizer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;

/**
 * Laporan data pembeli PDF untuk partner.
 *
 * Satu keluarga dengan KeuanganPdfService: kop sama, ringkasan berkartu, tabel
 * bergaris, catatan, tanda tangan, dan footer hak cipta + nomor halaman di
 * setiap halaman. Yang berbeda hanya isinya — data pembeli per pesanan, bukan
 * mutasi saldo.
 *
 * Data diambil dari ReportService::partnerBuyers, sumber yang sama dengan tabel
 * di layar dan ekspor CSV, supaya angka di ketiga tempat tidak pernah beda.
 */
class PembeliPdfService
{
    /**
     * Batas baris per PDF.
     *
     * DomPDF memuat seluruh dokumen ke memori dan menghitung layout dua kali
     * (reflow tabel), jadi dokumen raksasa bisa menghabiskan memory_limit PHP
     * di hosting bersama dan menghasilkan error 500 tanpa pesan jelas. Event
     * besar (10.000+ pesanan) lebih cocok memakai CSV yang streaming.
     */
    private const MAKS_BARIS = 2000;

    public function __construct(
        private ReportService $reports,
        private KeuanganPdfService $keuangan,
    ) {}

    /**
     * @return array{0: Dompdf, 1: string} PDF dan nama file
     */
    public function buat(int $organizerId): array
    {
        $semua = collect($this->reports->partnerBuyers($organizerId));

        // Pesanan batal tidak pernah jadi pembeli sah, dan pesanan yang belum
        // dibayar belum tentu jadi pembeli. Keduanya dikeluarkan dari laporan
        // resmi, tetapi jumlahnya tetap dilaporkan di catatan supaya partner
        // tahu ada data yang tidak ikut — bukan disembunyikan.
        $lunas = $semua->filter(fn (array $b) => $this->statusLunas($b['status'] ?? null));
        $batal = $semua->filter(fn (array $b) => $this->statusBatal($b['status'] ?? null));
        $belumBayar = $semua->count() - $lunas->count() - $batal->count();

        $terpotong = max(0, $lunas->count() - self::MAKS_BARIS);

        $baris = $lunas
            ->sortByDesc(fn (array $b) => $b['tanggal'] ?? '')
            ->take(self::MAKS_BARIS)
            ->values()
            ->map(fn (array $b) => [
                'kode' => $b['kode_order'],
                'tanggal' => $this->waktu($b['tanggal'] ?? null),
                'nama' => $b['nama'] ?: '—',
                'kontak' => $this->kontak($b),
                'event' => $b['event'] ?: 'Tanpa Event',
                'tiket' => $b['tiket'] ?: '—',
                'jumlah' => (int) ($b['jumlah'] ?? 0),
                'total' => (float) ($b['total'] ?? 0),
                'sumber' => ($b['sumber'] ?? Order::SUMBER_ONLINE) === Order::SUMBER_MANUAL
                    ? ($this->labelMetode($b['metode_bayar'] ?? null))
                    : 'Online',
                'diinput' => $b['dibuat_oleh'] ?: '—',
            ])
            ->all();

        $namaOrganizer = trim((string) (Organizer::find($organizerId)?->nama_penyelenggara ?: ''));

        $data = [
            'namaOrganizer' => $namaOrganizer,
            'dibuatPada' => Carbon::now()->translatedFormat('d F Y, H:i'),
            'ringkasan' => [
                'pesanan' => $lunas->count(),
                'pembeli' => $lunas->count(),
                'tiket' => (int) $lunas->sum('jumlah'),
                'nilai' => (float) $lunas->sum('total'),
            ],
            'jumlahBatal' => $batal->count(),
            'jumlahBelumBayar' => $belumBayar,
            'terpotong' => $terpotong,
            'baris' => $baris,
            'perEvent' => $this->ringkasPerEvent($lunas),
            'perSumber' => $this->ringkasPerSumber($lunas),
            'maksBaris' => self::MAKS_BARIS,
        ];

        $opsi = new Options;
        $opsi->setDefaultFont('DejaVu Sans');
        $opsi->setIsRemoteEnabled(false);
        $opsi->setIsHtml5ParserEnabled(true);
        $opsi->setChroot(base_path());

        $pdf = new Dompdf($opsi);
        $pdf->setPaper('a4', 'portrait');
        $pdf->loadHtml(view('reports.pembeli-pdf', $data)->render(), 'UTF-8');
        $pdf->render();

        $this->pasangFooter($pdf);

        return [$pdf, $this->namaBerkas($namaOrganizer)];
    }

    /**
     * Footer hak cipta + nomor halaman di setiap halaman.
     *
     * Sengaja page_text() langsung, bukan page_script() dengan callback yang
     * memanggil page_text(): processPageScript() menelusuri SEMUA halaman untuk
     * tiap script, sehingga nomor 1..N tergambar bertumpuk di setiap halaman.
     * Posisi kanan dihitung dari lebar halaman agar tidak terpotong.
     */
    private function pasangFooter(Dompdf $pdf): void
    {
        $fontMetrics = $pdf->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
        $ukuran = 8.5;
        $abu = [0.58, 0.64, 0.70];
        $margin = 30.0;
        $y = 810.0;

        $pdf->getCanvas()->page_text(
            $margin, $y, $this->keuangan->teksHakCipta(), $font, $ukuran, $abu,
        );

        $nomor = 'Halaman {PAGE_NUM} dari {PAGE_COUNT}';
        $lebarNomor = $fontMetrics->getTextWidth(
            str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], ['88', '88'], $nomor),
            $font, $ukuran,
        );

        $pdf->getCanvas()->page_text(
            $pdf->getCanvas()->get_width() - $margin - $lebarNomor, $y,
            $nomor, $font, $ukuran, $abu,
        );
    }

    /**
     * Nama berkas: Nontix_Laporan-Pembeli_<penyelenggara>_<YYYY-MM>.pdf
     *
     * Aturan sama dengan laporan keuangan: hanya huruf/angka/tanda hubung
     * (karakter "/" dan ":" gagal diunduh di Windows), tanpa jam agar unduhan
     * ulang menimpa berkas lama.
     */
    private function namaBerkas(?string $namaOrganizer): string
    {
        $bersih = fn (string $teks, int $maks): string => trim(
            substr(preg_replace('/[^A-Za-z0-9]+/', '-', $teks), 0, $maks),
            '-',
        );

        $penyelenggara = $bersih($namaOrganizer ?: 'Penyelenggara', 40) ?: 'Penyelenggara';

        return "Nontix_Laporan-Pembeli_{$penyelenggara}_".now()->format('Y-m').'.pdf';
    }

    /**
     * Kontak pembeli dalam satu sel: email di baris pertama, WhatsApp di
     * baris kedua. Kolom terpisah membuat tabel terlalu lebar untuk A4.
     */
    private function kontak(array $b): string
    {
        $baris = array_filter([
            trim((string) ($b['email'] ?? '')),
            trim((string) ($b['whatsapp'] ?? '')),
        ]);

        return $baris ? implode("\n", $baris) : '—';
    }

    private function labelMetode(?string $metode): string
    {
        return match ($metode) {
            'tunai' => 'Manual — Tunai',
            'transfer' => 'Manual — Transfer',
            'bayar_di_lokasi' => 'Manual — Bayar di Lokasi',
            default => 'Manual',
        };
    }

    private function ringkasPerEvent($lunas): array
    {
        return $lunas
            ->groupBy(fn (array $b) => $b['event'] ?: 'Tanpa Event')
            ->map(fn ($rows, $nama) => [
                'event' => $nama,
                'pesanan' => $rows->count(),
                'tiket' => (int) $rows->sum('jumlah'),
                'nilai' => (float) $rows->sum('total'),
            ])
            ->sortByDesc('nilai')
            ->values()
            ->all();
    }

    private function ringkasPerSumber($lunas): array
    {
        return $lunas
            ->groupBy(fn (array $b) => ($b['sumber'] ?? Order::SUMBER_ONLINE) === Order::SUMBER_MANUAL
                ? 'Manual'
                : 'Online')
            ->map(fn ($rows, $nama) => [
                'sumber' => $nama,
                'pesanan' => $rows->count(),
                'tiket' => (int) $rows->sum('jumlah'),
                'nilai' => (float) $rows->sum('total'),
            ])
            ->all();
    }

    /**
     * Status datang sebagai OrderStatus (hasil cast model), bukan string —
     * menerima keduanya supaya tidak rapuh kalau sumber datanya berubah.
     */
    private function statusLunas(OrderStatus|string|null $status): bool
    {
        $nilai = $status instanceof \BackedEnum ? $status->value : (string) $status;

        return $nilai === OrderStatus::PAID->value;
    }

    private function statusBatal(OrderStatus|string|null $status): bool
    {
        $nilai = $status instanceof \BackedEnum ? $status->value : (string) $status;

        return in_array($nilai, [
            OrderStatus::CANCELLED->value,
            OrderStatus::EXPIRED->value,
        ], true);
    }

    private function waktu(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->format('d/m/Y H:i') : '';
    }
}
