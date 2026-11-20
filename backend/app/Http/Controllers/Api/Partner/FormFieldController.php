<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Event;
use App\Models\FormField;
use App\Models\TicketType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            'event_id' => ['bail', 'nullable', 'integer', 'exists:events,id'],
            ...$this->ticketTargetRules(),
        ]);

        $data = $this->normalizeTicketTargets($data);
        $data['key'] = Str::slug($data['label'], '_') ?: 'field';
        $data['organizer_id'] = $this->organizerId($request);

        $field = DB::transaction(function () use ($request, $data) {
            $this->authorizeTargets($request, $data);

            return FormField::create($data);
        });

        return response()->json(['data' => $field->refresh()], 201);
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
            'event_id' => ['bail', 'nullable', 'integer', 'exists:events,id'],
            ...$this->ticketTargetRules(),
        ]);

        $data = $this->normalizeTicketTargets($data);

        if (array_key_exists('label', $data)) {
            $data['key'] = Str::slug($data['label'], '_') ?: 'field';
        }

        DB::transaction(function () use ($request, $data, $formField) {
            $this->authorizeTargets($request, $data + $formField->only(['event_id', 'ticket_type_id', 'ticket_type_ids']));
            $formField->update($data);
        });

        return response()->json(['data' => $formField->fresh()]);
    }

    public function destroy(Request $request, FormField $formField)
    {
        abort_if($formField->organizer_id !== $this->organizerId($request), 403, 'Field ini bukan milikmu.');
        $formField->delete();

        return response()->json(['message' => 'Field dihapus.']);
    }

    private function ticketTargetRules(): array
    {
        return [
            'ticket_type_id' => ['nullable', 'integer', 'exists:ticket_types,id'],
            'ticket_type_ids' => ['nullable', 'array', 'list', 'min:1'],
            'ticket_type_ids.*' => ['bail', 'required', 'integer', 'distinct', 'exists:ticket_types,id'],
        ];
    }

    private function normalizeTicketTargets(array $data): array
    {
        if (array_key_exists('ticket_type_ids', $data)) {
            $data['ticket_type_id'] = null;
            $data['ticket_type_ids'] = $data['ticket_type_ids'] === null
                ? null : array_map('intval', $data['ticket_type_ids']);
        } elseif (array_key_exists('ticket_type_id', $data)) {
            $data['ticket_type_ids'] = null;
        }

        return $data;
    }

    private function authorizeTargets(Request $request, array $data): void
    {
        $organizerId = $this->organizerId($request);
        $eventId = $data['event_id'] ?? null;
        if ($eventId !== null) {
            $owned = Event::where('organizer_id', $organizerId)->whereKey($eventId)->exists();
            abort_if(! $owned, 403, 'Event tidak valid.');
        }

        $ids = $data['ticket_type_ids'] ?? (isset($data['ticket_type_id']) ? [$data['ticket_type_id']] : []);
        if ($ids === []) {
            return;
        }

        if ($eventId === null) {
            throw ValidationException::withMessages(['event_id' => 'Event wajib diisi jika jenis tiket dipilih.']);
        }

        // Hold the targets until the field write commits so deletion cannot leave a stale JSON reference.
        $tickets = TicketType::whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId))
            ->whereKey($ids)->orderBy('id')->sharedLock()->get(['id', 'event_id']);
        abort_if($tickets->count() !== count($ids), 403, 'Jenis tiket tidak valid.');
        abort_if(
            $tickets->contains(fn ($ticket) => (int) $ticket->event_id !== (int) $eventId),
            422, 'Jenis tiket tidak termasuk dalam event tersebut.',
        );
    }
}
