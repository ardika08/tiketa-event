<?php

namespace App\Services;

use App\Enums\PayoutStatus;
use App\Models\Order;
use App\Models\Organizer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;

/**
 * Laporan keuangan PDF untuk partner.
 *
 * Disusun di server, bukan di browser, karena PDF yang dihasilkan klien tidak
 * bisa diberi nomor halaman, kop konsisten, atau diarsipkan sebagai berkas
 * resmi. Datanya diambil dari ReportService — sumber yang sama dengan halaman
 * Keuangan dan ekspor CSV — supaya tidak ada dua versi angka.
 *
 * Format mutasi meniru laporan "Balance Report" payment gateway: satu baris
 * untuk setiap kejadian uang, dengan saldo berjalan. Tujuannya agar laporan
 * bisa direkonsiliasi baris per baris, bukan sekadar tabel total.
 */
class KeuanganPdfService
{
    public function __construct(private ReportService $reports) {}

    /**
     * @return array{0: Dompdf, 1: string} PDF dan nama file
     */
    public function buat(int $organizerId): array
    {
        $finance = $this->reports->partnerFinance($organizerId);
        $summary = $finance['summary'];
        $payout = $finance['payout'];
        $transaksi = collect($finance['transaksi']);

        [$mutasi, $saldoAkhir] = $this->susunMutasi($transaksi, $payout['data'] ?? []);

        // Nama di kop diambil dari data penyelenggara, bukan dari akun login:
        // satu penyelenggara bisa punya lebih dari satu pengguna.
        $namaOrganizer = trim((string) (Organizer::find($organizerId)?->nama_penyelenggara ?: ''));

        $data = [
            'namaOrganizer' => $namaOrganizer,
            'dibuatPada' => Carbon::now()->translatedFormat('d F Y, H:i'),
            'summary' => [
                'tiket' => (int) ($summary['tiket_terjual'] ?? 0),
                'kotor' => (float) ($summary['pendapatan_kotor'] ?? 0),
                'biaya' => (float) ($summary['biaya_layanan'] ?? 0),
                'bersih' => (float) ($summary['pendapatan_bersih'] ?? 0),
            ],
            'ringkasan' => $payout['ringkasan'] ?? [],
            'mutasi' => $mutasi,
            'saldoAkhir' => $saldoAkhir,
            'perEvent' => $this->ringkasPerEvent($transaksi, $summary),
            'payout' => collect($payout['data'] ?? [])
                ->sortBy(fn (array $p) => $p['diajukan_at'] ?? '')
                ->values()
                ->map(fn (array $p) => [
                'id' => $p['id'],
                'diajukan' => $this->waktu($p['diajukan_at'] ?? null),
                'selesai' => $this->waktu($p['selesai_at'] ?? null),
                'bank' => $p['nama_bank'] ?: '—',
                'norek' => $p['nomor_rekening'] ?: '—',
                'atas_nama' => $p['nama_rekening'] ?: '—',
                'jumlah' => (float) ($p['jumlah'] ?? 0),
                'status' => ucfirst($p['status'] ?? '—'),
                'catatan_admin' => $p['catatan_admin'] ?? '',
            ])->all(),
            'biayaLayanan' => (float) config('nontix.service_fee'),
        ];

        $opsi = new Options;
        $opsi->setDefaultFont('DejaVu Sans');
        $opsi->setIsRemoteEnabled(false);   // laporan tidak perlu menarik aset luar
        $opsi->setIsHtml5ParserEnabled(true);
        $opsi->setChroot(base_path());

        $pdf = new Dompdf($opsi);
        $pdf->setPaper('a4', 'portrait');
        $pdf->loadHtml(view('reports.keuangan-pdf', $data)->render(), 'UTF-8');
        $pdf->render();

        // Footer hak cipta + nomor halaman di SETIAP halaman.
        //
        // PENTING: pakai page_text(), bukan page_script() dengan callback yang
        // memanggil page_text() di dalamnya. processPageScript() menelusuri
        // SEMUA halaman untuk tiap script, jadi callback bertingkat menggambar
        // seluruh nomor halaman (1..N) di setiap halaman — bertumpuk di satu
        // titik dan tampak sebagai kotak gelap/blob.
        //
        // page_text() juga sudah mengganti {PAGE_NUM}/{PAGE_COUNT} sendiri,
        // jadi nomornya benar per halaman tanpa hitung manual.
        //
        // Posisi kanan dihitung dari lebar halaman, bukan koordinat tetap,
        // supaya tidak terpotong saat jumlah halaman dua digit.
        $fontMetrics = $pdf->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
        $ukuran = 8.5;
        $abu = [0.58, 0.64, 0.70];
        $margin = 30.0;
        $y = 810.0;

        $pdf->getCanvas()->page_text($margin, $y, $this->teksHakCipta(), $font, $ukuran, $abu);

        // Lebar diukur dengan angka 2 digit sebagai perkiraan terburuk, supaya
        // posisi kanan tidak bergeser saat jumlah halaman berubah.
        $nomor = 'Halaman {PAGE_NUM} dari {PAGE_COUNT}';
        $lebarNomor = $fontMetrics->getTextWidth(
            str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], ['88', '88'], $nomor),
            $font, $ukuran,
        );
        $pdf->getCanvas()->page_text(
            $pdf->getCanvas()->get_width() - $margin - $lebarNomor, $y,
            $nomor, $font, $ukuran, $abu,
        );

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($namaOrganizer ?: 'nontix'));
        $namaFile = 'laporan-keuangan-'.trim($slug, '-').'-'.now()->format('Ymd-Hi').'.pdf';

        return [$pdf, $namaFile];
    }

    /**
     * Teks hak cipta untuk footer PDF. Satu sumber kebenaran — kalau entitas
     * hukumnya berubah, cukup ubah di sini, bukan di tiap template.
     */
    public function teksHakCipta(): string
    {
        return '© '.date('Y').' Nontix — PT. DIARMA AKSARA MEDIA. Seluruh hak cipta dilindungi.';
    }

    /**
     * Susun baris mutasi: penjualan masuk, biaya layanan, lalu pencairan.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    private function susunMutasi($transaksi, array $payouts): array
    {
        $kronologis = $transaksi
            ->sortBy(fn (array $t) => $t['tanggal'] ?? '')
            ->values();

        $baris = [];
        $saldo = 0.0;

        foreach ($kronologis as $t) {
            $bruto = (float) ($t['bruto'] ?? 0);
            $biaya = (float) ($t['biaya_layanan'] ?? 0);
            $jumlahTiket = (int) ($t['jumlah_tiket'] ?? 0);
            $pembeli = $t['nama_pembeli'] ?: '—';
            $kanal = $this->kanal($t);

            $saldo += $bruto;
            $baris[] = [
                'tanggal' => $this->waktu($t['tanggal'] ?? null),
                'jenis' => 'Penjualan Tiket',
                'referensi' => $t['kode_order'],
                'uraian' => $pembeli.' — '.($t['event'] ?: 'Tanpa Event').', '.$jumlahTiket.' tiket',
                'tipe' => 'Masuk',
                'kelas' => 'masuk',
                'kanal' => $kanal,
                'nominal' => $bruto,
                'saldo' => $saldo,
            ];

            if ($biaya > 0) {
                $saldo -= $biaya;
                $baris[] = [
                    'tanggal' => $this->waktu($t['tanggal'] ?? null),
                    'jenis' => 'Biaya Layanan',
                    'referensi' => $t['kode_order'],
                    'uraian' => 'Biaya platform '.$jumlahTiket.' tiket',
                    'tipe' => 'Biaya',
                    'kelas' => 'biaya',
                    'kanal' => $kanal,
                    'nominal' => -$biaya,
                    'saldo' => $saldo,
                ];
            }
        }

        foreach (collect($payouts)->sortBy(fn (array $p) => $p['diajukan_at'] ?? '')->values() as $p) {
            if (($p['status'] ?? '') === PayoutStatus::DITOLAK->value) {
                continue; // dana tidak pernah keluar
            }

            $jumlah = (float) ($p['jumlah'] ?? 0);
            $saldo -= $jumlah;
            $baris[] = [
                'tanggal' => $this->waktu($p['diajukan_at'] ?? $p['selesai_at'] ?? null),
                'jenis' => 'Pencairan Dana',
                'referensi' => 'PAYOUT-'.$p['id'],
                'uraian' => 'Transfer ke '.($p['nama_bank'] ?: '—').' '.($p['nomor_rekening'] ?: '').' a/n '.($p['nama_rekening'] ?: '—'),
                'tipe' => 'Keluar',
                'kelas' => 'keluar',
                'kanal' => 'Transfer Bank',
                'nominal' => -$jumlah,
                'saldo' => $saldo,
            ];
        }

        return [$baris, $saldo];
    }

    private function ringkasPerEvent($transaksi, array $summary): array
    {
        return $transaksi
            ->groupBy(fn (array $t) => $t['event'] ?: 'Tanpa Event')
            ->map(fn ($rows, $nama) => [
                'event' => $nama,
                'tiket' => (int) $rows->sum('jumlah_tiket'),
                'kotor' => (float) $rows->sum('bruto'),
                'biaya' => (float) $rows->sum('biaya_layanan'),
                'bersih' => (float) $rows->sum('netto'),
            ])
            ->sortByDesc('kotor')
            ->values()
            ->all();
    }

    private function kanal(array $t): string
    {
        $manual = ($t['sumber'] ?? Order::SUMBER_ONLINE) === Order::SUMBER_MANUAL;

        if (! $manual) {
            return 'Mayar';
        }

        return match ($t['metode_bayar'] ?? null) {
            'tunai' => 'Tunai',
            'transfer' => 'Transfer',
            'bayar_di_lokasi' => 'Bayar di Lokasi',
            default => 'Manual',
        };
    }

    private function waktu(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->format('d/m/Y H:i') : '';
    }
}
