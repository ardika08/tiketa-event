<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventSession;
use Illuminate\Http\Request;

class EventController extends PartnerController
{
    private function relations(): array
    {
        return [
            'organizer',
            'sessions',
            'ticketTypes' => fn ($q) => $q->withSum(
                ['orderItems as terjual_lunas' => fn ($o) => $o->whereHas('order', fn ($x) => $x->where('status', 'lunas'))],
                'jumlah',
            ),
            'ticketTypes.sessions',
        ];
    }

    public function index(Request $request)
    {
        $events = Event::where('organizer_id', $this->organizerId($request))
            ->with($this->relations())
            ->latest()
            ->get();

        return EventResource::collection($events);
    }

    public function store(Request $request)
    {
        $organizer = $this->organizer($request);
        $data = $this->validated($request);

        $event = Event::create($data + ['organizer_id' => $organizer->id]);

        if ($request->has('sessions')) {
            $this->syncSessions($event, $request->input('sessions', []));
        }

        return (new EventResource($event->fresh($this->relations())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Event $event)
    {
        $this->authorizeEvent($request, $event);

        return new EventResource($event->load($this->relations()));
    }

    public function update(Request $request, Event $event)
    {
        $this->authorizeEvent($request, $event);

        $data = $this->validated($request, partial: true);
        $event->update($data);

        if ($request->has('sessions')) {
            $this->syncSessions($event, $request->input('sessions', []));
        }

        return new EventResource($event->fresh($this->relations()));
    }

    public function destroy(Request $request, Event $event)
    {
        $this->authorizeEvent($request, $event);
        $event->delete();

        return response()->json(['message' => 'Event dihapus.']);
    }

    public function storeSession(Request $request, Event $event)
    {
        $this->authorizeEvent($request, $event);
        $data = $this->validatedSession($request);
        $session = $event->sessions()->create($data);

        return response()->json(['data' => $session], 201);
    }

    public function updateSession(Request $request, Event $event, EventSession $session)
    {
        $this->authorizeEvent($request, $event);
        abort_if($session->event_id !== $event->id, 404);
        $session->update($this->validatedSession($request, partial: true));

        return response()->json(['data' => $session->fresh()]);
    }

    public function destroySession(Request $request, Event $event, EventSession $session)
    {
        $this->authorizeEvent($request, $event);
        abort_if($session->event_id !== $event->id, 404);
        $session->delete();

        return response()->json(['message' => 'Sesi dihapus.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'nama_event' => [$required, 'string', 'max:180'],
            'kategori' => ['nullable', 'string', 'max:60'],
            'deskripsi' => ['nullable', 'string'],
            'hero_image_url' => ['nullable', 'string', 'max:500'],
            'tanggal_mulai' => ['nullable', 'date'],
            'tanggal_selesai' => ['nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'lokasi' => ['nullable', 'string', 'max:200'],
            'syarat_ketentuan' => ['nullable', 'string'],
            'sembunyikan_sisa_kuota' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:draft,aktif,selesai'],
        ]);
    }

    private function validatedSession(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'label' => [$required, 'string', 'max:60'],
            'nama_session' => [$required, 'string', 'max:120'],
            'tanggal_mulai' => ['nullable', 'date'],
            'tanggal_selesai' => ['nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'lokasi' => ['nullable', 'string', 'max:200'],
            'kapasitas' => ['nullable', 'integer', 'min:0'],
            'urutan' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ]);
    }

    private function syncSessions(Event $event, array $sessions): void
    {
        $keepIds = [];

        foreach ($sessions as $index => $row) {
            $payload = [
                'label' => $row['label'],
                'nama_session' => $row['nama_session'],
                'tanggal_mulai' => $row['tanggal_mulai'] ?? null,
                'tanggal_selesai' => $row['tanggal_selesai'] ?? null,
                'lokasi' => $row['lokasi'] ?? null,
                'kapasitas' => $row['kapasitas'] ?? 0,
                'urutan' => $row['urutan'] ?? $index + 1,
                'status' => $row['status'] ?? 'aktif',
            ];

            if (! empty($row['id'])) {
                $session = $event->sessions()->find($row['id']);
                if ($session) {
                    $session->update($payload);
                    $keepIds[] = $session->id;
                    continue;
                }
            }

            $keepIds[] = $event->sessions()->create($payload)->id;
        }

        $event->sessions()->whereNotIn('id', $keepIds)->delete();
    }
}
