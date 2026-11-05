<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\FormField;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $events = Event::query()
            ->active()
            ->with(['organizer:id,nama_penyelenggara,logo_url', 'sessions', 'ticketTypes.sessions'])
            ->when($request->query('q'), fn ($q, $v) => $q->where('nama_event', 'like', "%{$v}%"))
            ->when($request->query('kategori'), fn ($q, $v) => $q->where('kategori', $v))
            ->when($request->query('lokasi'), fn ($q, $v) => $q->where('lokasi', 'like', "%{$v}%"))
            ->orderBy('tanggal_mulai')
            ->get();

        return EventResource::collection($events);
    }

    public function show(string $slug)
    {
        $event = Event::query()
            ->active()
            ->where('slug', $slug)
            ->with(['organizer', 'sessions', 'ticketTypes.sessions', 'seatPlan', 'formFields'])
            ->firstOrFail();

        // Field organizer-level (event_id NULL) berlaku untuk semua event milik
        // organizer ini — digabung dengan field spesifik event, urut sesuai `urutan`.
        $organizerFields = FormField::where('organizer_id', $event->organizer_id)
            ->whereNull('event_id')
            ->whereNull('ticket_type_id') // field khusus tiket tidak relevan di level organizer
            ->where('status', 'aktif')
            ->orderBy('urutan')
            ->get();

        $event->setRelation(
            'formFields',
            $event->formFields->concat($organizerFields)->sortBy('urutan')->values(),
        );

        return new EventResource($event);
    }

    /**
     * Satu tiket untuk halaman checkout langsung.
     *
     * Sengaja mengembalikan event + SATU tiket saja, supaya pembeli yang datang
     * lewat link khusus tidak melihat atau memilih tiket lain. Tiket `sembunyi`
     * tetap boleh dibuka di sini — itu justru tujuan link ini.
     */
    public function tiket(Request $request, string $slug, string $ticketSlug)
    {
        $event = Event::query()
            ->active()
            ->where('slug', $slug)
            ->with(['organizer', 'sessions', 'seatPlan', 'formFields'])
            ->firstOrFail();

        $ticket = $event->ticketTypes()
            ->where('slug', $ticketSlug)
            ->where('status', 'aktif')
            ->with('sessions')
            ->first();

        abort_if($ticket === null, 404, 'Tiket tidak ditemukan atau sudah tidak dijual.');

        // Tandai permintaan supaya EventResource tidak membuang tiket ini
        // (tiket sembunyi justru boleh dibuka lewat link langsung).
        $request->attributes->set('checkout_tiket', true);

        $organizerFields = FormField::where('organizer_id', $event->organizer_id)
            ->whereNull('event_id')
            ->whereNull('ticket_type_id')
            ->where('status', 'aktif')
            ->orderBy('urutan')
            ->get();

        // Field khusus tiket ini tetap dipakai walau event-level tidak dimuat,
        // karena checkout butuh semua pertanyaan yang relevan.
        $ticketFields = FormField::where('event_id', $event->id)
            ->where('ticket_type_id', $ticket->id)
            ->where('status', 'aktif')
            ->orderBy('urutan')
            ->get();

        $eventFields = FormField::where('event_id', $event->id)
            ->whereNull('ticket_type_id')
            ->where('status', 'aktif')
            ->orderBy('urutan')
            ->get();

        $event->setRelation(
            'formFields',
            $eventFields->concat($ticketFields)->concat($organizerFields)->sortBy('urutan')->values(),
        );
        $event->setRelation('ticketTypes', collect([$ticket]));

        return new EventResource($event);
    }
}
