<?php

namespace App\Support;

use Carbon\CarbonInterface;

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
     * Status untuk panel gelombang di halaman scan.
     *
     * @return 'dibuka'|'belum_dibuka'|'sudah_ditutup'|'tanpa_jadwal'
     */
    public static function statusKategori(?string $mulai, ?string $selesai, CarbonInterface $sekarang): string
    {
        return match (self::statusJam($mulai, $selesai, $sekarang)) {
            'belum' => 'belum_dibuka',
            'lewat' => 'sudah_ditutup',
            'dalam' => 'dibuka',
            default => 'tanpa_jadwal',
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
     * Menit dari sekarang sampai jam tujuan (0..1439), untuk hitungan "dibuka
     * dalam X menit" di layar petugas. Dihitung di server supaya zona waktu
     * perangkat petugas tidak berpengaruh.
     */
    public static function menitKeJam(?string $jam, CarbonInterface $sekarang): ?int
    {
        if (! $jam) {
            return null;
        }

        $selisih = self::detik($jam) - self::detik($sekarang->format('H:i:s'));

        if ($selisih < 0) {
            $selisih += 86400;
        }

        return (int) ceil($selisih / 60);
    }

    /** Ubah "08:00" / "08:00:00" menjadi detik sejak tengah malam. */
    private static function detik(string $jam): int
    {
        $bagian = array_map('intval', explode(':', $jam));

        return ($bagian[0] ?? 0) * 3600 + ($bagian[1] ?? 0) * 60 + ($bagian[2] ?? 0);
    }
}
