<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Event;
use App\Models\FormField;
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
        ]);

        if (! empty($data['event_id'])) {
            $owned = Event::where('organizer_id', $this->organizerId($request))->whereKey($data['event_id'])->exists();
            abort_if(! $owned, 403, 'Event tidak valid.');
        }

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
        ]);

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
}
