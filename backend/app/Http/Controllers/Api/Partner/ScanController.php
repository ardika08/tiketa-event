<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Gate;
use App\Models\Staff;
use App\Models\TicketType;
use App\Services\CheckinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScanController extends PartnerController
{
    public function sessions(Request $request, Event $event)
    {
        $this->authorizeEvent($request, $event);

        $sessions = $event->sessions()
            ->withCount([
                'ticketPasses as total_pass',
                'ticketPasses as total_hadir' => fn ($q) => $q->where('status_kehadiran', 'hadir'),
            ])
            ->get();

        return response()->json(['data' => $sessions]);
    }

    public function scan(Request $request, CheckinService $service)
    {
        $data = $request->validate([
            'event_id' => ['required', 'exists:events,id'],
            'session_id' => ['required', 'exists:event_sessions,id'],
            'kode_qr' => ['required', 'string'],
            'gate_id' => ['nullable', 'exists:gates,id'],
            'staff_id' => ['nullable', 'exists:staffs,id'],
        ]);

        $event = Event::where('organizer_id', $this->organizerId($request))->findOrFail($data['event_id']);
        $session = EventSession::where('event_id', $event->id)->findOrFail($data['session_id']);
        $gate = isset($data['gate_id']) ? Gate::find($data['gate_id']) : null;
        $staff = isset($data['staff_id']) ? Staff::find($data['staff_id']) : null;

        $result = $service->scan($event, $session, $data['kode_qr'], $gate, $staff);

        $pass = $result['pass'] ?? null;
        unset($result['pass']);

        return response()->json($result + [
            'ticket' => $pass ? [
                'kode_tiket' => $pass->ticket?->kode_tiket,
                'nama_pemegang' => $pass->ticket?->nama_pemegang,
                'nama_tiket' => $pass->ticket?->ticketType?->nama_tiket,
            ] : null,
        ]);
    }

    public function attendance(Request $request)
    {
        $data = $request->validate([
            'event_id' => ['required', 'exists:events,id'],
            'session_id' => ['nullable', 'exists:event_sessions,id'],
        ]);

        $event = Event::where('organizer_id', $this->organizerId($request))->findOrFail($data['event_id']);

        $sessions = $event->sessions()
            ->withCount([
                'ticketPasses as total_pass',
                'ticketPasses as total_hadir' => fn ($q) => $q->where('status_kehadiran', 'hadir'),
            ])
            ->get()
            ->map(fn (EventSession $session) => [
                'id' => $session->id,
                'label' => $session->label,
                'nama_session' => $session->nama_session,
                'total_pass' => $session->total_pass,
                'total_hadir' => $session->total_hadir,
                'persentase' => $session->total_pass > 0
                    ? round(($session->total_hadir / $session->total_pass) * 100)
                    : 0,
            ]);

        // Rekap kehadiran per KATEGORI tiket. Populasi = ticket_passes yang sudah
        // terbit (hanya dibuat untuk order lunas, lihat OrderService::generateTickets),
        // jadi totalnya konsisten dengan angka "Total Tiket" di kartu statistik.
        // Kategori yang belum ada penjualan tetap dikirim dengan angka 0 supaya
        // daftarnya tidak pernah "menghilang" dari mata partner.
        $kategori = TicketType::query()
            ->where('event_id', $event->id)
            ->orderBy('nama_tiket')
            ->get(['id', 'nama_tiket'])
            ->mapWithKeys(fn (TicketType $ticketType) => [
                $ticketType->id => [
                    'id' => $ticketType->id,
                    'nama_tiket' => $ticketType->nama_tiket,
                    'total_pass' => 0,
                    'total_hadir' => 0,
                    'persentase' => 0,
                    // Rincian per sesi; kunci = event_session_id ("tanpa_sesi" bila null).
                    'per_sesi' => [],
                ],
            ])
            ->all();

        // Satu query agregat untuk semua kombinasi kategori x sesi (anti N+1).
        $perKategoriSesi = DB::table('ticket_passes')
            ->join('tickets', 'tickets.id', '=', 'ticket_passes.ticket_id')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->where('orders.event_id', $event->id)
            ->groupBy('tickets.ticket_type_id', 'ticket_passes.event_session_id')
            ->selectRaw(
                'tickets.ticket_type_id, ticket_passes.event_session_id, '
                .'COUNT(*) as total_pass, '
                .'SUM(CASE WHEN ticket_passes.status_kehadiran = ? THEN 1 ELSE 0 END) as total_hadir',
                ['hadir'],
            )
            ->get();

        foreach ($perKategoriSesi as $baris) {
            $id = (int) $baris->ticket_type_id;

            if (! isset($kategori[$id])) {
                continue; // kategori milik event lain / sudah dihapus
            }

            $kunci = $baris->event_session_id === null ? 'tanpa_sesi' : (string) $baris->event_session_id;
            $pass = (int) $baris->total_pass;
            $hadir = (int) $baris->total_hadir;

            $kategori[$id]['total_pass'] += $pass;
            $kategori[$id]['total_hadir'] += $hadir;
            $kategori[$id]['per_sesi'][$kunci] = [
                'total_pass' => $pass,
                'total_hadir' => $hadir,
            ];
        }

        $kategori = array_values(array_map(function (array $baris) {
            $baris['persentase'] = $baris['total_pass'] > 0
                ? (int) round(($baris['total_hadir'] / $baris['total_pass']) * 100)
                : 0;

            return $baris;
        }, $kategori));

        $checkins = Checkin::where('event_id', $event->id)
            ->when($data['session_id'] ?? null, fn ($q, $v) => $q->where('event_session_id', $v))
            ->with(['session:id,label,nama_session', 'gate:id,nama_gate'])
            ->latest('checked_in_at')
            ->get();

        return response()->json([
            'sessions' => $sessions,
            'kategori' => $kategori,
            'checkins' => $checkins,
        ]);
    }
}
