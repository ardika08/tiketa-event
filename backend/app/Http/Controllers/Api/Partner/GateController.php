<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Event;
use App\Models\Gate;
use Illuminate\Http\Request;

class GateController extends PartnerController
{
    public function index(Request $request)
    {
        $gates = Gate::where('organizer_id', $this->organizerId($request))
            ->with('event:id,nama_event')
            ->latest()
            ->get();

        return response()->json(['data' => $gates]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nama_gate' => ['required', 'string', 'max:120'],
            'event_id' => ['nullable', 'exists:events,id'],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ]);

        if (! empty($data['event_id'])) {
            $owned = Event::where('organizer_id', $this->organizerId($request))->whereKey($data['event_id'])->exists();
            abort_if(! $owned, 403, 'Event tidak valid.');
        }

        $gate = Gate::create($data + ['organizer_id' => $this->organizerId($request)]);

        return response()->json(['data' => $gate], 201);
    }

    public function update(Request $request, Gate $gate)
    {
        abort_if($gate->organizer_id !== $this->organizerId($request), 403, 'Gate ini bukan milikmu.');

        $gate->update($request->validate([
            'nama_gate' => ['sometimes', 'string', 'max:120'],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ]));

        return response()->json(['data' => $gate->fresh()]);
    }

    public function destroy(Request $request, Gate $gate)
    {
        abort_if($gate->organizer_id !== $this->organizerId($request), 403, 'Gate ini bukan milikmu.');
        $gate->delete();

        return response()->json(['message' => 'Gate dihapus.']);
    }
}
