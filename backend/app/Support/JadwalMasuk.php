<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Carbon\Carbon;

/**
 * Satu-satunya tempat aturan "boleh masuk sekarang atau tidak" dihitung.
 *
 * Dipakai oleh CheckinService (saat memindai QR) dan ScanController (saat
 * menampilkan panel gelombang di halaman scan), supaya aturan di gerbang dan
 * informasi di layar petugas tidak pernah berbeda.
 *
 * Semua perbandingan memakai jam SERVER (APP_TIMEZONE = Asia/Jakarta), bukan jam
 * perangkat petugas. Ini disengaja: jam HP yang salah tidak boleh bisa membuka
 * gate lebih awal.
 *
 * Ada dua lapis jendela waktu:
 *   1. Jendela SESI  (event_sessions.tanggal_mulai / tanggal_selesai, datetime)
 *      → menentukan hari/blok mana yang sedang berjalan ("kunci hari").
 *   2. Jendela KATEGORI (ticket_types.jam_masuk_mulai / jam_masuk_selesai, time)
 *      → menentukan gelombang jam masuk tiket ("gelombang").
 *
 * Keduanya opsional. Kalau kosong, lapisan itu tidak membatasi apa pun — ini yang
 * menjaga tiket lama tetap bisa masuk seperti sebelumnya.
 */
class JadwalMasuk
{
    /**
     * Status jendela jam kategori tiket.
     *
     * @return 'belum'|'dalam'|'lewat'|null  null = kategori tidak dibatasi jam
     */
    public static function statusJam(?string $mulai, ?string $selesai, CarbonInterface $sekarang): ?string
    {
        if (! $mulai && ! $selesai) {
            return null;
        }

        $detikSekarang = self::detik($sekarang->format('H:i:s'));
        $detikMulai = $mulai ? self::detik($mulai) : null;
        $detikSelesai = $selesai ? self::detik($selesai) : null;

        // Jendela yang melewati tengah malam, mis. 22:00–02:00.
        if ($detikMulai !== null && $detikSelesai !== null && $detikMulai > $detikSelesai) {
            return ($detikSekarang >= $detikMulai || $detikSekarang <= $detikSelesai) ? 'dalam' : 'belum';
        }

        if ($detikMulai !== null && $detikSekarang < $detikMulai) {
            return 'belum';
        }

        if ($detikSelesai !== null && $detikSekarang > $detikSelesai) {
            return 'lewat';
        }

        return 'dalam';
    }

    /**
     * Status jendela tanggal sesi.
     *
     * @return 'belum'|'dalam'|'lewat'|null  null = tanggal sesi belum diisi
     */
    public static function statusTanggal(mixed $mulai, mixed $selesai, CarbonInterface $sekarang): ?string
    {
        if (! $mulai && ! $selesai) {
            return null;
        }

        if ($mulai && $sekarang->lt($mulai)) {
            return 'belum';
        }

        if ($selesai && $sekarang->gt($selesai)) {
            return 'lewat';
        }

        return 'dalam';
    }

    /**
     * Berapa menit SEBELUM jam masuk gerbang sudah menerima QR.
     *
     * Satu kebijakan untuk seluruh platform (config nontix.buka_lebih_awal_menit),
     * bukan pengaturan per kategori — keputusan user: hanya gelombang pertama
     * yang benar-benar terdampak, gelombang berikutnya sudah bisa di-scan karena
     * gelombang sebelumnya masih berjalan.
     *
     * 0 = mati (perilaku bawaan). Dibatasi 0..240 supaya salah isi .env tidak
     * membuka gerbang berjam-jam lebih awal tanpa disadari.
     */
    public static function menitBukaLebihAwal(): int
    {
        return max(0, min(240, (int) config('nontix.buka_lebih_awal_menit', 0)));
    }

    /**
     * Jam masuk yang benar-benar ditegakkan gerbang: jam masuk resmi digeser
     * "buka lebih awal". Jam tutup TIDAK ikut bergeser — pengunjung hanya bisa
     * masuk lebih awal, bukan lebih lama.
     */
    public static function jamBukaEfektif(?string $jamMasuk): ?string
    {
        if (! $jamMasuk) {
            return null;
        }

        $lebihAwal = self::menitBukaLebihAwal();

        if ($lebihAwal <= 0) {
            return self::jam($jamMasuk);
        }

        $detik = (self::detik($jamMasuk) - $lebihAwal * 60) % 86400;

        if ($detik < 0) {
            $detik += 86400;
        }

        return sprintf('%02d:%02d', intdiv($detik, 3600) % 24, intdiv($detik, 60) % 60);
    }

    /**
     * Status untuk panel gelombang di halaman scan.
     *
     * $sesiMulai / $sesiSelesai = jendela hari sesi yang sedang ditampilkan.
     *
     * Konteks hari WAJIB ikut dihitung. Tanpa itu panel salah: pukul 14:00 di
     * hari biasa, semua kategori berjam pagi akan terbaca "sudah ditutup"
     * padahal gerbangnya belum pernah dibuka hari itu. Urutannya:
     *   1. sebelum sesi dibuka  → semua kategori "belum_dibuka"
     *   2. setelah sesi ditutup → semua kategori "sudah_ditutup"
     *   3. di dalam sesi        → bandingkan jam kategori seperti biasa
     *
     * @return 'dibuka'|'belum_dibuka'|'sudah_ditutup'|'tanpa_jadwal'
     */
    public static function statusKategori(
        ?string $mulai,
        ?string $selesai,
        CarbonInterface $sekarang,
        mixed $sesiMulai = null,
        mixed $sesiSelesai = null,
    ): string {
        $buka = self::jamBukaEfektif($mulai);

        if (self::statusJam($buka, $selesai, $sekarang) === null) {
            return 'tanpa_jadwal';
        }

        $sesiMulai = self::waktu($sesiMulai);
        $sesiSelesai = self::waktu($sesiSelesai);

        if ($sesiMulai && $sekarang->lt($sesiMulai)) {
            return 'belum_dibuka';
        }

        if ($sesiSelesai && $sekarang->gt($sesiSelesai)) {
            return 'sudah_ditutup';
        }

        return match (self::statusJam($buka, $selesai, $sekarang)) {
            'belum' => 'belum_dibuka',
            'lewat' => 'sudah_ditutup',
            default => 'dibuka',
        };
    }

    /**
     * Status untuk pemilihan sesi otomatis di halaman scan.
     *
     * @return 'berjalan'|'akan_datang'|'sudah_lewat'|'tanpa_jadwal'
     */
    public static function statusSesi(mixed $mulai, mixed $selesai, CarbonInterface $sekarang): string
    {
        return match (self::statusTanggal($mulai, $selesai, $sekarang)) {
            'belum' => 'akan_datang',
            'lewat' => 'sudah_lewat',
            'dalam' => 'berjalan',
            default => 'tanpa_jadwal',
        };
    }

    /** "08:00:00" / "8:00" → "08:00" untuk ditampilkan. */
    public static function jam(?string $jam): string
    {
        if (! $jam) {
            return '';
        }

        $detik = self::detik($jam);

        return sprintf('%02d:%02d', intdiv($detik, 3600) % 24, intdiv($detik, 60) % 60);
    }

    /** "08:00–10:00" / "mulai 08:00" / "sampai 10:00" / null bila tanpa jam. */
    public static function labelJam(?string $mulai, ?string $selesai): ?string
    {
        $awal = self::jam($mulai);
        $akhir = self::jam($selesai);

        return match (true) {
            $awal !== '' && $akhir !== '' => $awal.'–'.$akhir,
            $awal !== '' => 'mulai '.$awal,
            $akhir !== '' => 'sampai '.$akhir,
            default => null,
        };
    }

    /**
     * Menit sampai kategori ini benar-benar dibuka, sudah memperhitungkan hari
     * sesinya. Dipakai untuk label "dibuka dalam 51 hari 15 jam" di layar
     * petugas — dihitung di server supaya jam perangkat petugas tidak
     * berpengaruh.
     *
     * null = tidak ada yang dihitung (tanpa jam masuk, atau sudah lewat).
     */
    public static function menitKeBukaKategori(
        ?string $jamMulai,
        CarbonInterface $sekarang,
        mixed $sesiMulai = null,
    ): ?int {
        if (! $jamMulai) {
            return null;
        }

        $bukaJam = self::jamBukaEfektif($jamMulai);

        // Geseran yang melewati tengah malam mundur (mis. 00:15 dengan 30 menit
        // lebih awal -> 23:45 hari sebelumnya).
        $melingkar = self::detik($bukaJam) > self::detik($jamMulai);

        // Sebelum sesi dibuka, jam buka kategori jatuh pada hari sesi dimulai.
        $sesiMulai = self::waktu($sesiMulai);
        $hariAcuan = $sesiMulai && $sekarang->lt($sesiMulai)
            ? $sesiMulai->copy()->startOfDay()
            : $sekarang->copy()->startOfDay();

        $buka = $hariAcuan->addSeconds(self::detik($bukaJam));

        if ($melingkar) {
            $buka->subDay();
        }

        if ($buka->lte($sekarang)) {
            return null;
        }

        return (int) ceil($sekarang->diffInSeconds($buka) / 60);
    }

    /** Normalisasi tanggal dari Eloquent / string / null menjadi Carbon. */
    private static function waktu(mixed $nilai): ?CarbonInterface
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return $nilai instanceof CarbonInterface ? $nilai : Carbon::parse($nilai);
    }

    /** Ubah "08:00" / "08:00:00" menjadi detik sejak tengah malam. */
    private static function detik(string $jam): int
    {
        $bagian = array_map('intval', explode(':', $jam));

        return ($bagian[0] ?? 0) * 3600 + ($bagian[1] ?? 0) * 60 + ($bagian[2] ?? 0);
    }
}
