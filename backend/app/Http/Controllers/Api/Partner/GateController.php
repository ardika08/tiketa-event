<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Checkin;
use App\Models\Gate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GateController extends PartnerController
{
    public function index(Request $request)
    {
        $gates = Gate::where('organizer_id', $this->organizerId($request))
            ->with('event:id,nama_event')
            ->withCount('staffs')
            ->orderByRaw("CASE WHEN status = 'aktif' THEN 0 ELSE 1 END")
            ->orderBy('nama_gate')
            ->get();

        return response()->json(['data' => $gates]);
    }

    public function store(Request $request)
    {
        $organizerId = $this->organizerId($request);

        $data = $request->validate([
            'nama_gate' => ['required', 'string', 'max:120'],
            // Event wajib milik organizer ini (sebelumnya dicek terpisah dengan
            // abort 403, sehingga di form muncul sebagai error umum, bukan error
            // di kolom event).
            'event_id' => ['nullable', Rule::exists('events', 'id')->where('organizer_id', $organizerId)],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ], [
            'event_id.exists' => 'Event itu bukan milikmu.',
        ]);

        $gate = Gate::create($data + ['organizer_id' => $organizerId]);

        return response()->json([
            'data' => $gate->load('event:id,nama_event')->loadCount('staffs'),
        ], 201);
    }

    public function update(Request $request, Gate $gate)
    {
        $organizerId = $this->organizerId($request);

        abort_if($gate->organizer_id !== $organizerId, 403, 'Gate ini bukan milikmu.');

        // event_id ikut bisa diubah: gate yang terlanjur tertaut ke event yang
        // salah harus bisa dibetulkan tanpa menghapus dan membuat ulang.
        $gate->update($request->validate([
            'nama_gate' => ['sometimes', 'string', 'max:120'],
            'event_id' => ['nullable', Rule::exists('events', 'id')->where('organizer_id', $organizerId)],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ], [
            'event_id.exists' => 'Event itu bukan milikmu.',
        ]));

        return response()->json([
            'data' => $gate->fresh()->load('event:id,nama_event')->loadCount('staffs'),
        ]);
    }

    public function destroy(Request $request, Gate $gate)
    {
        abort_if($gate->organizer_id !== $this->organizerId($request), 403, 'Gate ini bukan milikmu.');

        // checkins.gate_id memakai nullOnDelete: kalau gate dihapus, jejak "masuk
        // lewat gate mana" hilang selamanya dari riwayat. Riwayat check-in tidak
        // boleh kehilangan konteks, jadi gate yang sudah terpakai hanya boleh
        // dinonaktifkan.
        $terpakai = Checkin::where('gate_id', $gate->id)->exists();

        abort_if(
            $terpakai,
            409,
            'Gate ini sudah dipakai di riwayat check-in, jadi tidak bisa dihapus. Nonaktifkan saja.',
        );

        $jumlahStaff = $gate->staffs()->count();

        $gate->delete();

        return response()->json([
            'message' => $jumlahStaff > 0
                ? "Gate dihapus. {$jumlahStaff} staff sekarang tanpa gate."
                : 'Gate dihapus.',
        ]);
    }
}
