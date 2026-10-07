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
use Illuminate\Support\Facades\DB;

class CheckinService
{
    /**
     * Pindai QR tiket pada sebuah sesi.
     *
     * Seluruh proses dibungkus transaksi + row lock (lockForUpdate) agar
     * dua petugas yang memindai QR yang sama secara nyaris bersamaan tidak
     * bisa sama-sama lolos check-in (anti race condition di pintu masuk).
     *
     * @return array{status:string, message:string, pass?:TicketPass}
     */
    public function scan(
        Event $event,
        EventSession $session,
        string $kodeQr,
        ?Gate $gate = null,
        ?Staff $staff = null,
    ): array {
        $kode = strtoupper(trim($kodeQr));

        return DB::transaction(function () use ($event, $session, $kode, $gate, $staff) {
            $record = function (CheckinStatus $status, string $catatan, ?TicketPass $pass) use ($event, $session, $gate, $staff, $kode) {
                return Checkin::create([
                    'ticket_pass_id' => $pass?->id,
                    'event_id' => $event->id,
                    'event_session_id' => $session->id,
                    'gate_id' => $gate?->id,
                    'staff_id' => $staff?->id,
                    'kode_qr' => $kode,
                    'nama_pemegang' => $pass?->ticket?->nama_pemegang,
                    'status' => $status,
                    'catatan' => $catatan,
                    'checked_in_at' => now(),
                ]);
            };

            // Kunci baris pass ini sampai transaksi selesai.
            $pass = TicketPass::with('ticket.ticketType', 'ticket.order')
                ->where('kode_qr', $kode)
                ->lockForUpdate()
                ->first();

            if (! $pass) {
                $record(CheckinStatus::FAILED, 'Kode QR tidak dikenal', null);

                return ['status' => 'invalid', 'message' => 'Kode QR tidak dikenal.'];
            }

            if (! $pass->ticket?->order?->isPaid()) {
                $record(CheckinStatus::FAILED, 'Pesanan belum lunas', $pass);

                return ['status' => 'unpaid', 'message' => 'Tiket ini belum dibayar — tidak bisa check-in.'];
            }

            // PENGAMAN (Fase 2): kategori yang tidak punya sesi menghasilkan pass
            // dengan event_session_id = NULL. Sebelumnya pass seperti itu SELALU
            // ditolak di gate mana pun (tiket mati). Sekarang pass tanpa sesi boleh
            // dipakai SEKALI di gate mana pun, asalkan masih di event yang sama.
            // Tiket normal (event_session_id terisi) tidak terpengaruh sama sekali.
            $tanpaSesi = $pass->event_session_id === null;

            if ($tanpaSesi) {
                if ($pass->ticket?->order?->event_id !== $event->id) {
                    $record(CheckinStatus::FAILED, 'QR bukan untuk event ini', $pass);

                    return ['status' => 'wrong_session', 'message' => 'QR ini bukan untuk event ini.', 'pass' => $pass];
                }
            } elseif ($pass->event_session_id !== $session->id) {
                $record(CheckinStatus::FAILED, 'QR bukan untuk sesi ini', $pass);

                return ['status' => 'wrong_session', 'message' => 'QR ini bukan untuk sesi '.$session->label.'.', 'pass' => $pass];
            }

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

            $pass = $pass->fresh('ticket.ticketType');
            $record(CheckinStatus::SUCCESS, 'Check-in berhasil', $pass);

            return ['status' => 'valid', 'message' => 'QR valid — selamat masuk!', 'pass' => $pass];
        });
    }
}
