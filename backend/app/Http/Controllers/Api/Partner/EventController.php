<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Ticket;
use Illuminate\Http\Exceptions\HttpResponseException;
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

        if ($request->has('sessions')) {
            // PENGAMAN: form event menghapus sesi lewat mass-delete di syncSessions().
            // Dicek SEBELUM $event->update() supaya event tidak setengah tersimpan
            // ketika penghapusan sesi ditolak.
            $keepIds = collect($request->input('sessions', []))->pluck('id')->filter()->all();
            $idsTerhapus = $event->sessions()->whereNotIn('id', $keepIds)->pluck('id')->all();
            $this->pastikanSesiBolehDihapus($event, $idsTerhapus);
        }

        $event->update($data);

        if ($request->has('sessions')) {
            $this->syncSessions($event, $request->input('sessions', []));
        }

        return new EventResource($event->fresh($this->relations()));
    }

    public function destroy(Request $request, Event $event)
    {
        $this->authorizeEvent($request, $event);

        // PENGAMAN: event memakai SoftDeletes sehingga baris tiket/QR tetap utuh,
        // TETAPI event yang terhapus hilang dari daftar event sehingga petugas
        // tidak bisa memilihnya di halaman scan -> tiket pembeli jadi tidak bisa
        // dipakai. Karena itu event yang sudah ada penjualan tidak boleh dihapus.
        $jumlahTiket = Ticket::whereHas('order', fn ($q) => $q->where('event_id', $event->id))->count();

        if ($jumlahTiket > 0) {
            return response()->json([
                'message' => "Event \"{$event->nama_event}\" tidak bisa dihapus karena sudah ada {$jumlahTiket} tiket terjual. "
                    .'Kalau acaranya sudah selesai, ubah status event menjadi "Selesai" — tiket pembeli tetap aman dan bisa di-scan.',
                'kode' => 'event_punya_tiket',
                'jumlah_tiket' => $jumlahTiket,
            ], 409);
        }

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

        $this->pastikanSesiBolehDihapus($event, [$session->id]);

        $session->delete();

        return response()->json(['message' => 'Sesi dihapus.']);
    }

    /**
     * PENGAMAN: sesi yang akan dihapus tidak boleh punya tiket terjual, karena FK
     * ticket_passes.event_session_id memakai cascadeOnDelete sehingga menghapus
     * sesi akan IKUT MENGHAPUS QR pembeli (tiket pembeli jadi mati).
     *
     * Dipakai oleh dua jalur penghapusan: endpoint destroySession DAN mass-delete
     * di syncSessions() yang dipakai form event (tombol tong sampah pada baris sesi).
     *
     * @param  array<int, int>  $idsToDelete
     */
    private function pastikanSesiBolehDihapus(Event $event, array $idsToDelete): void
    {
        if ($idsToDelete === []) {
            return;
        }

        $terkunci = $event->sessions()
            ->whereIn('id', $idsToDelete)
            ->withCount('ticketPasses')
            ->get()
            ->filter(fn ($s) => $s->ticket_passes_count > 0);

        if ($terkunci->isEmpty()) {
            return;
        }

        $rincian = $terkunci
            ->map(fn ($s) => "\"{$s->label}\" ({$s->ticket_passes_count} tiket)")
            ->implode(', ');

        throw new HttpResponseException(response()->json([
            'message' => "Tidak bisa menghapus sesi {$rincian} karena sudah ada tiket terjual. "
                .'Menghapus sesi akan ikut menghapus QR pembeli. Kalau perlu diubah, cukup ganti nama sesinya.',
            'kode' => 'sesi_punya_tiket',
            'sesi' => $terkunci->map(fn ($s) => [
                'id' => $s->id,
                'label' => $s->label,
                'jumlah_tiket' => $s->ticket_passes_count,
            ])->values()->all(),
        ], 409));
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
