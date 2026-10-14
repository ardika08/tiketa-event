<?php

namespace Tests\Unit;

use App\Support\JadwalMasuk;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Panel gelombang di halaman scan pernah salah baca: pukul 14:00 di hari biasa
 * (jauh sebelum hari-H), semua kategori berjam pagi terbaca "SUDAH TUTUP"
 * padahal gerbangnya belum pernah dibuka hari itu. Sebabnya perbandingan hanya
 * memakai jam, tanpa konteks hari sesi. Test ini mengunci perbaikannya.
 */
class JadwalMasukTest extends TestCase
{
    private const SESI_MULAI = '2026-12-05 08:00:00';

    private const SESI_SELESAI = '2026-12-05 20:00:00';

    private function sesi(): array
    {
        return [Carbon::parse(self::SESI_MULAI), Carbon::parse(self::SESI_SELESAI)];
    }

    public function test_di_luar_hari_sesi_semua_kategori_belum_dibuka(): void
    {
        [$mulai, $selesai] = $this->sesi();
        $sekarang = Carbon::parse('2026-10-14 14:00:00');

        // Jamnya sudah lewat hari ini, tapi harinya belum tiba.
        $this->assertSame('belum_dibuka', JadwalMasuk::statusKategori('08:00', '09:00', $sekarang, $mulai, $selesai));
        $this->assertSame('belum_dibuka', JadwalMasuk::statusKategori('13:00', '16:00', $sekarang, $mulai, $selesai));
    }

    public function test_setelah_hari_sesi_semua_kategori_sudah_ditutup(): void
    {
        [$mulai, $selesai] = $this->sesi();
        $sekarang = Carbon::parse('2026-12-07 10:00:00');

        $this->assertSame('sudah_ditutup', JadwalMasuk::statusKategori('13:00', '16:00', $sekarang, $mulai, $selesai));
    }

    public function test_di_dalam_hari_sesi_kembali_memakai_jam_kategori(): void
    {
        [$mulai, $selesai] = $this->sesi();
        $sekarang = Carbon::parse('2026-12-05 08:30:00');

        $this->assertSame('dibuka', JadwalMasuk::statusKategori('08:00', '09:00', $sekarang, $mulai, $selesai));
        $this->assertSame('belum_dibuka', JadwalMasuk::statusKategori('09:00', '10:00', $sekarang, $mulai, $selesai));
        $this->assertSame('sudah_ditutup', JadwalMasuk::statusKategori('07:00', '08:00', $sekarang, $mulai, $selesai));
    }

    public function test_sebelum_sesi_dibuka_pada_hari_yang_sama_masih_belum_dibuka(): void
    {
        [$mulai, $selesai] = $this->sesi();
        $sekarang = Carbon::parse('2026-12-05 07:00:00');

        $this->assertSame('belum_dibuka', JadwalMasuk::statusKategori('08:00', '09:00', $sekarang, $mulai, $selesai));
    }

    public function test_kategori_tanpa_jam_tetap_tanpa_jadwal(): void
    {
        [$mulai, $selesai] = $this->sesi();
        $sekarang = Carbon::parse('2026-10-14 14:00:00');

        $this->assertSame('tanpa_jadwal', JadwalMasuk::statusKategori(null, null, $sekarang, $mulai, $selesai));
    }

    public function test_tanpa_tanggal_sesi_perilaku_lama_tetap_berlaku(): void
    {
        $sekarang = Carbon::parse('2026-10-14 14:00:00');

        // Tiket event lama yang sesinya belum diisi tanggal tidak boleh berubah.
        $this->assertSame('sudah_ditutup', JadwalMasuk::statusKategori('08:00', '09:00', $sekarang));
        $this->assertSame('belum_dibuka', JadwalMasuk::statusKategori('20:00', '21:00', $sekarang));
    }

    public function test_hitung_mundur_di_hari_sesi_memakai_jam_kategori(): void
    {
        $sekarang = Carbon::parse('2026-12-05 07:00:00');

        $this->assertSame(
            60,
            JadwalMasuk::menitKeBukaKategori('08:00', $sekarang, Carbon::parse(self::SESI_MULAI)),
        );
    }

    public function test_hitung_mundur_sebelum_hari_sesi_memakai_hari_pertama_sesi(): void
    {
        // 14 Okt 14:00 → 5 Des 08:00 = 51 hari 18 jam.
        $sekarang = Carbon::parse('2026-10-14 14:00:00');

        $this->assertSame(
            74520,
            JadwalMasuk::menitKeBukaKategori('08:00', $sekarang, Carbon::parse(self::SESI_MULAI)),
        );
    }

    public function test_hitung_mundur_null_kalau_jamnya_sudah_lewat_atau_tanpa_jam(): void
    {
        $sekarang = Carbon::parse('2026-12-05 09:00:00');

        $this->assertNull(JadwalMasuk::menitKeBukaKategori('08:00', $sekarang, Carbon::parse(self::SESI_MULAI)));
        $this->assertNull(JadwalMasuk::menitKeBukaKategori(null, $sekarang, Carbon::parse(self::SESI_MULAI)));
    }

    public function test_jendela_lewat_tengah_malam_didukung(): void
    {
        $this->assertSame(
            'dibuka',
            JadwalMasuk::statusKategori('22:00', '02:00', Carbon::parse('2026-10-14 23:30:00')),
        );

        $this->assertSame(
            'dibuka',
            JadwalMasuk::statusKategori('22:00', '02:00', Carbon::parse('2026-10-14 01:00:00')),
        );

        $this->assertSame(
            'belum_dibuka',
            JadwalMasuk::statusKategori('22:00', '02:00', Carbon::parse('2026-10-14 12:00:00')),
        );
    }

    public function test_tanggal_sesi_boleh_dikirim_sebagai_string(): void
    {
        $sekarang = Carbon::parse('2026-10-14 14:00:00');

        $this->assertSame(
            'belum_dibuka',
            JadwalMasuk::statusKategori('08:00', '09:00', $sekarang, self::SESI_MULAI, self::SESI_SELESAI),
        );
    }
}
