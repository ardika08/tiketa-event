<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Resources\TicketTypeResource;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Http\Request;

class TicketTypeController extends PartnerController
{
    public function index(Request $request)
    {
        $query = TicketType::query()
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $this->organizerId($request)))
            ->with('sessions')
            ->when($request->query('event_id'), fn ($q, $v) => $q->where('event_id', $v))
            ->orderBy('event_id')
            ->orderBy('harga');

        return TicketTypeResource::collection($query->get());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $event = Event::where('organizer_id', $this->organizerId($request))->findOrFail($data['event_id']);

        $data['sisa_kuota'] = $data['sisa_kuota'] ?? $data['kuota'];
        $ticketType = $event->ticketTypes()->create($data);

        $this->syncSessionsTo($ticketType, $request->input('session_ids', []));

        return (new TicketTypeResource($ticketType->fresh('sessions')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, TicketType $ticketType)
    {
        $this->authorizeTicket($request, $ticketType);

        return new TicketTypeResource($ticketType->load('sessions'));
    }

    public function update(Request $request, TicketType $ticketType)
    {
        $this->authorizeTicket($request, $ticketType);

        $data = $this->validated($request, partial: true);

        if (array_key_exists('kuota', $data) && ! array_key_exists('sisa_kuota', $data)) {
            $terjual = $ticketType->kuota - $ticketType->sisa_kuota;
            $data['sisa_kuota'] = max(0, $data['kuota'] - $terjual);
        }

        $ticketType->update($data);

        if ($request->has('session_ids')) {
            $this->syncSessionsTo($ticketType, $request->input('session_ids', []));
        }

        return new TicketTypeResource($ticketType->fresh('sessions'));
    }

    public function destroy(Request $request, TicketType $ticketType)
    {
        $this->authorizeTicket($request, $ticketType);
        $ticketType->delete();

        return response()->json(['message' => 'Jenis tiket dihapus.']);
    }

    public function syncSessions(Request $request, TicketType $ticketType)
    {
        $this->authorizeTicket($request, $ticketType);

        $data = $request->validate([
            'session_ids' => ['present', 'array'],
            'session_ids.*' => ['integer'],
        ]);

        $this->syncSessionsTo($ticketType, $data['session_ids']);

        return new TicketTypeResource($ticketType->fresh('sessions'));
    }

    private function syncSessionsTo(TicketType $ticketType, array $sessionIds): void
    {
        $valid = $ticketType->event->sessions()
            ->whereIn('id', $sessionIds)
            ->pluck('id')
            ->all();

        $ticketType->sessions()->sync($valid);
        $ticketType->update(['is_bundle' => count($valid) > 1]);
    }

    private function authorizeTicket(Request $request, TicketType $ticketType): void
    {
        abort_if(
            $ticketType->event->organizer_id !== $this->organizerId($request),
            403,
            'Jenis tiket ini bukan milikmu.',
        );
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'event_id' => [$partial ? 'sometimes' : 'required', 'exists:events,id'],
            'nama_tiket' => [$required, 'string', 'max:150'],
            'harga' => [$required, 'numeric', 'min:0'],
            'kuota' => [$required, 'integer', 'min:1'],
            'sisa_kuota' => ['nullable', 'integer', 'min:0'],
            'max_per_order' => ['nullable', 'integer', 'min:1'],
            'gambar_url' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ]);
    }
}
