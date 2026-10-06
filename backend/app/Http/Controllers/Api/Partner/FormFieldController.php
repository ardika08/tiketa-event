<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Event;
use App\Models\FormField;
use App\Models\TicketType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FormFieldController extends PartnerController
{
    public function index(Request $request)
    {
        $fields = FormField::where('organizer_id', $this->organizerId($request))
            ->when($request->query('event_id'), fn ($q, $v) => $q->where('event_id', $v))
            ->orderBy('urutan')
            ->get();

        return response()->json(['data' => $fields]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'tipe' => ['required', 'in:teks,email,nomor,sosial'],
            'wajib' => ['nullable', 'boolean'],
            'urutan' => ['nullable', 'integer', 'min:1'],
            'event_id' => ['nullable', 'exists:events,id'],
            'ticket_type_id' => ['nullable', 'exists:ticket_types,id'],
        ]);

        $this->authorizeTargets($request, $data);

        $data['key'] = Str::slug($data['label'], '_') ?: 'field';
        $data['organizer_id'] = $this->organizerId($request);

        $field = FormField::create($data);

        return response()->json(['data' => $field], 201);
    }

    public function update(Request $request, FormField $formField)
    {
        abort_if($formField->organizer_id !== $this->organizerId($request), 403, 'Field ini bukan milikmu.');

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'tipe' => ['nullable', 'in:teks,email,nomor,sosial'],
            'wajib' => ['nullable', 'boolean'],
            'urutan' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:aktif,nonaktif'],
            'event_id' => ['nullable', 'exists:events,id'],
            'ticket_type_id' => ['nullable', 'exists:ticket_types,id'],
        ]);

        $this->authorizeTargets($request, $data);

        if (array_key_exists('label', $data)) {
            $data['key'] = Str::slug($data['label'], '_') ?: 'field';
        }

        $formField->update($data);

        return response()->json(['data' => $formField->fresh()]);
    }

    public function destroy(Request $request, FormField $formField)
    {
        abort_if($formField->organizer_id !== $this->organizerId($request), 403, 'Field ini bukan milikmu.');
        $formField->delete();

        return response()->json(['message' => 'Field dihapus.']);
    }

    /**
     * Field hanya boleh menunjuk ke event/tiket milik organizer yang sama.
     * ticket_type_id wajib satu event dengan event_id field (bila keduanya terisi).
     */
    private function authorizeTargets(Request $request, array $data): void
    {
        $organizerId = $this->organizerId($request);

        if (! empty($data['event_id'])) {
            $owned = Event::where('organizer_id', $organizerId)->whereKey($data['event_id'])->exists();
            abort_if(! $owned, 403, 'Event tidak valid.');
        }

        if (! empty($data['ticket_type_id'])) {
            $ticketType = TicketType::whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId))
                ->find($data['ticket_type_id']);
            abort_if(! $ticketType, 403, 'Jenis tiket tidak valid.');

            if (! empty($data['event_id']) && (int) $ticketType->event_id !== (int) $data['event_id']) {
                abort(422, 'Jenis tiket tidak termasuk dalam event tersebut.');
            }
        }
    }
}
