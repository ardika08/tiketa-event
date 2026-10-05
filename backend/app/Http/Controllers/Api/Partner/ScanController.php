<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Gate;
use App\Models\Staff;
use App\Services\CheckinService;
use Illuminate\Http\Request;

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

        $checkins = Checkin::where('event_id', $event->id)
            ->when($data['session_id'] ?? null, fn ($q, $v) => $q->where('event_session_id', $v))
            ->with(['session:id,label,nama_session', 'gate:id,nama_gate'])
            ->latest('checked_in_at')
            ->get();

        return response()->json([
            'sessions' => $sessions,
            'checkins' => $checkins,
        ]);
    }
}
