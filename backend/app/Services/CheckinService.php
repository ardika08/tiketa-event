<?php

namespace App\Services;

use App\Enums\CheckinStatus;
use App\Enums\TicketStatus;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Gate;
use App\Models\Staff;
use App\Models\TicketPass;
use App\Support\JadwalMasuk;
use Illuminate\Support\Facades\DB;

class CheckinService
{
    /**
     * Pindai QR tiket pada sebuah event.
     *
     * Seluruh proses dibungkus transaksi + row lock (lockForUpdate) agar
     * dua petugas yang memindai QR yang sama secara nyaris bersamaan tidak
     * bisa sama-sama lolos check-in (anti race condition di pintu masuk).
     *
     * URUTAN PEMERIKSAAN — yang pertama gagal itulah pesan yang keluar:
     *   1. QR dikenal                        → kalau tidak  : invalid
     *   2. Pesanan lunas                     → kalau tidak  : unpaid
     *   3. QR milik event ini                → kalau tidak  : wrong_session
     *   4. KUNCI HARI  — sesi milik QR ini sendiri sedang berjalan?
     *                                        → belum/lewat  : not_open / closed
     *   5. GELOMBANG   — jam masuk kategori tiket sedang dibuka?
     *                                        → belum/lewat  : not_open / closed
     *   6. Belum pernah dipakai              → kalau sudah  : used
     *
     * Sesi TIDAK dipilih manual oleh petugas lagi. Sesi pencatatan diambil dari
     * QR itu sendiri ($pass->event_session_id), jadi petugas tidak mungkin salah
     * pilih sesi dan menolak tiket yang sebenarnya sah. Parameter $session hanya
     * dipakai sebagai cadangan untuk event yang tanggal sesinya belum diisi —
     * di situ perilakunya sama persis dengan sebelumnya.
     *
     * @return array{status:string, message:string, pass?:TicketPass}
     */
    public function scan(
        Event $event,
        ?EventSession $session,
        string $kodeQr,
        ?Gate $gate = null,
        ?Staff $staff = null,
    ): array {
        $kode = strtoupper(trim($kodeQr));

        return DB::transaction(function () use ($event, $session, $kode, $gate, $staff) {
            $sekarang = now();

            // Sesi pencatatan. Dimulai dari sesi yang dikirim klien (cadangan),
            // lalu ditimpa oleh sesi milik QR begitu pass-nya terbaca.
            $sesiCatat = $session;

            $record = function (CheckinStatus $status, string $catatan, ?TicketPass $pass) use ($event, $gate, $staff, $kode, $sekarang, &$sesiCatat) {
                return Checkin::create([
                    'ticket_pass_id' => $pass?->id,
                    'event_id' => $event->id,
                    'event_session_id' => $sesiCatat?->id,
                    'gate_id' => $gate?->id,
                    'staff_id' => $staff?->id,
                    'kode_qr' => $kode,
                    'nama_pemegang' => $pass?->ticket?->nama_pemegang,
                    'status' => $status,
                    'catatan' => $catatan,
                    'checked_in_at' => $sekarang,
                ]);
            };

            // Kunci baris pass ini sampai transaksi selesai.
            $pass = TicketPass::with('ticket.ticketType', 'ticket.order', 'session')
                ->where('kode_qr', $kode)
                ->lockForUpdate()
                ->first();

            if (! $pass) {
                $record(CheckinStatus::FAILED, 'Kode QR tidak dikenal', null);

                return ['status' => 'invalid', 'message' => 'Kode QR tidak dikenal.'];
            }

            if (! $pass->ticket?->order?->isPaid()) {
                $record(CheckinStatus::FAILED, 'Pesanan belum lunas', $pass);

                return ['status' => 'unpaid', 'message' => 'Tiket ini belum dibayar — tidak bisa check-in.', 'pass' => $pass];
            }

            if ($pass->ticket->order->event_id !== $event->id) {
                $record(CheckinStatus::FAILED, 'QR bukan untuk event ini', $pass);

                return ['status' => 'wrong_session', 'message' => 'QR ini bukan untuk event ini.', 'pass' => $pass];
            }

            // Sesi pencatatan = sesi milik QR itu sendiri. Pass tanpa sesi
            // (kategori yang tidak diikat ke sesi mana pun) tetap memakai sesi
            // yang dikirim klien, persis seperti perilaku sebelumnya.
            $sesiPass = $pass->session;

            if ($sesiPass) {
                $sesiCatat = $sesiPass;
            }

            // ---- 4. KUNCI HARI (otomatis dari tanggal sesi) ----
            if ($sesiPass) {
                $jendelaSesi = JadwalMasuk::statusTanggal($sesiPass->tanggal_mulai, $sesiPass->tanggal_selesai, $sekarang);

                if ($jendelaSesi === 'belum') {
                    $record(CheckinStatus::FAILED, 'Sesi belum dibuka', $pass);

                    return [
                        'status' => 'not_open',
                        'message' => 'Sesi '.$this->labelSesi($sesiPass).' baru dibuka '.$this->waktu($sesiPass->tanggal_mulai).'. Sekarang '.$this->waktu($sekarang).'.',
                        'pesan_pengunjung' => 'Mohon maaf, sesi '.$this->labelSesi($sesiPass).' baru dapat dimasuki mulai '.$this->waktu($sesiPass->tanggal_mulai).'.',
                        'pass' => $pass,
                    ];
                }

                if ($jendelaSesi === 'lewat') {
                    $record(CheckinStatus::FAILED, 'Sesi sudah berakhir', $pass);

                    return [
                        'status' => 'closed',
                        'message' => 'Sesi '.$this->labelSesi($sesiPass).' sudah berakhir '.$this->waktu($sesiPass->tanggal_selesai).'.',
                        'pesan_pengunjung' => 'Mohon maaf, sesi '.$this->labelSesi($sesiPass).' telah berakhir '.$this->waktu($sesiPass->tanggal_selesai).'.',
                        'pass' => $pass,
                    ];
                }

                // Tanggal sesi belum diisi (jendelaSesi === null) → kembali ke
                // pemeriksaan sesi manual supaya event lama tidak berubah
                // perilakunya dan tidak ada gate yang tiba-tiba longgar.
                if ($jendelaSesi === null && $session && $session->id !== $sesiPass->id) {
                    $record(CheckinStatus::FAILED, 'QR bukan untuk sesi ini', $pass);

                    return [
                        'status' => 'wrong_session',
                        'message' => 'Tiket ini untuk '.$this->labelSesi($sesiPass).', bukan '.$this->labelSesi($session).'.',
                        'pass' => $pass,
                    ];
                }
            }

            // ---- 5. GELOMBANG: jam masuk kategori tiket ----
            // Kategori tanpa jam (jam_masuk_mulai & selesai kosong) tidak
            // dibatasi sama sekali — tiket lama aman.
            $kategori = $pass->ticket?->ticketType;

            if ($kategori) {
                $jendelaKategori = JadwalMasuk::statusJam($kategori->jam_masuk_mulai, $kategori->jam_masuk_selesai, $sekarang);

                if ($jendelaKategori === 'belum') {
                    $record(CheckinStatus::FAILED, 'Di luar jam masuk kategori', $pass);

                    return [
                        'status' => 'not_open',
                        'message' => 'Tiket '.$kategori->nama_tiket.' baru dibuka pukul '.JadwalMasuk::jam($kategori->jam_masuk_mulai).' WIB. Sekarang '.$sekarang->format('H:i').' WIB.',
                        'pesan_pengunjung' => 'Mohon maaf, tiket '.$kategori->nama_tiket.' baru dapat masuk pukul '.JadwalMasuk::jam($kategori->jam_masuk_mulai).' WIB.',
                        'pass' => $pass,
                    ];
                }

                if ($jendelaKategori === 'lewat') {
                    $record(CheckinStatus::FAILED, 'Di luar jam masuk kategori', $pass);

                    return [
                        'status' => 'closed',
                        'message' => 'Waktu masuk tiket '.$kategori->nama_tiket.' sudah berakhir pukul '.JadwalMasuk::jam($kategori->jam_masuk_selesai).' WIB.',
                        'pesan_pengunjung' => 'Mohon maaf, waktu masuk tiket '.$kategori->nama_tiket.' telah berakhir pukul '.JadwalMasuk::jam($kategori->jam_masuk_selesai).' WIB.',
                        'pass' => $pass,
                    ];
                }
            }

            // ---- 6. Sudah pernah dipakai? ----
            if ($pass->status_kehadiran === TicketStatus::CHECKED_IN) {
                $record(CheckinStatus::FAILED, 'QR sudah dipakai', $pass);

                return ['status' => 'used', 'message' => 'QR sudah dipakai untuk sesi ini.', 'pass' => $pass];
            }

            $pass->update([
                'status_kehadiran' => TicketStatus::CHECKED_IN,
                'checkin_at' => now(),
            ]);

            $ticket = $pass->ticket;
            $belumHadir = $ticket->passes()
                ->where('status_kehadiran', TicketStatus::NOT_CHECKED_IN->value)
                ->exists();

            if (! $belumHadir) {
                $ticket->update([
                    'status_kehadiran' => TicketStatus::CHECKED_IN,
                    'checkin_at' => now(),
                ]);
            }

            $pass = $pass->fresh(['ticket.ticketType', 'session']);
            $record(CheckinStatus::SUCCESS, 'Check-in berhasil', $pass);

            return ['status' => 'valid', 'message' => 'QR valid — selamat masuk!', 'pass' => $pass];
        });
    }

    /** "Day 2 (Puncak)" — label sesi untuk pesan ke petugas. */
    private function labelSesi(EventSession $sesi): string
    {
        return $sesi->nama_session
            ? $sesi->label.' ('.$sesi->nama_session.')'
            : $sesi->label;
    }

    /** "16 Nov 2026, 08:00" — waktu absolut untuk pesan ke petugas. */
    private function waktu(mixed $carbon): string
    {
        return $carbon ? $carbon->format('d M Y, H:i') : '-';
    }
}
