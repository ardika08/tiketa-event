<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Resources\TicketTypeResource;
use App\Models\Event;
use App\Models\TicketType;
use App\Support\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        // sisa_kuota = angka turunan: kategori baru selalu mulai dengan kuota penuh.
        $data['sisa_kuota'] = $data['kuota'];

        // Transaksi: kalau sesi ditolak (Fase 3), baris kategori ikut dibatalkan
        // supaya tidak tertinggal kategori "yatim" yang tanpa sesi.
        $ticketType = DB::transaction(function () use ($event, $data, $request) {
            $ticketType = $event->ticketTypes()->create($data);
            $this->syncSessionsTo($ticketType, $request->input('session_ids', []));

            return $ticketType;
        });

        StockLedger::record($ticketType, $ticketType->sisa_kuota, StockLedger::CREATED);

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

        $kuotaLama = $ticketType->kuota;
        $sisaLama = $ticketType->sisa_kuota;

        // sisa_kuota tidak bisa dikirim klien (lihat validated()). Kalau kuota
        // diubah, sisa dihitung ulang supaya tiket yang sudah terjual tetap
        // terhitung: terjual = kuota_lama - sisa_lama.
        if (array_key_exists('kuota', $data)) {
            $terjual = $kuotaLama - $sisaLama;
            $data['sisa_kuota'] = max(0, $data['kuota'] - $terjual);
        }

        // Transaksi: perubahan field dan perubahan sesi harus sukses bersama-sama,
        // supaya penolakan sesi (Fase 3) tidak menyisakan perubahan setengah jalan.
        DB::transaction(function () use ($request, $ticketType, $data) {
            $ticketType->update($data);

            if ($request->has('session_ids')) {
                $this->syncSessionsTo($ticketType, $request->input('session_ids', []));
            }
        });

        $ticketType->refresh();

        if ($ticketType->sisa_kuota !== $sisaLama) {
            StockLedger::record(
                $ticketType,
                $ticketType->sisa_kuota - $sisaLama,
                StockLedger::QUOTA_EDIT,
                "kuota {$kuotaLama} -> {$ticketType->kuota}",
            );
        }

        return new TicketTypeResource($ticketType->fresh('sessions'));
    }

    public function destroy(Request $request, TicketType $ticketType)
    {
        $this->authorizeTicket($request, $ticketType);

        // PENGAMAN: FK tickets.ticket_type_id memakai cascadeOnDelete, sehingga
        // menghapus kategori tiket akan IKUT MENGHAPUS semua tiket + QR pembeli
        // yang sudah dibeli untuk kategori ini (dipanggil form event saat partner
        // menekan tombol hapus pada baris kategori).
        $jumlahTiket = $ticketType->tickets()->count();

        if ($jumlahTiket > 0) {
            $jumlahPesanan = $ticketType->tickets()->distinct()->count('order_id');

            return response()->json([
                'message' => "Kategori \"{$ticketType->nama_tiket}\" tidak bisa dihapus karena sudah ada {$jumlahTiket} tiket terjual "
                    ."dari {$jumlahPesanan} pesanan. Menghapusnya akan ikut menghapus QR pembeli. "
                    .'Kalau tiketnya sudah tidak dijual, ubah statusnya menjadi nonaktif.',
                'kode' => 'kategori_punya_tiket',
                'jumlah_tiket' => $jumlahTiket,
                'jumlah_pesanan' => $jumlahPesanan,
            ], 409);
        }

        $ticketType->delete();

        return response()->json(['message' => 'Jenis tiket dihapus.']);
    }

    public function syncSessions(Request $request, TicketType $ticketType)
    {
        $this->authorizeTicket($request, $ticketType);

        $data = $request->validate([
            'session_ids' => ['present', 'array', 'min:1'],
            'session_ids.*' => ['integer'],
        ], [
            'session_ids.min' => 'Kategori tiket harus punya minimal 1 sesi supaya tiketnya bisa dipakai check-in.',
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

        // FASE 3: kategori WAJIB punya minimal 1 sesi. Kategori tanpa sesi
        // menghasilkan pass dengan event_session_id = NULL, dan pass seperti itu
        // tidak bisa dipakai check-in di gate mana pun -> tiket pembeli jadi mati.
        // Dijaga di sini juga supaya berlaku untuk SEMUA jalur (store/update/sync).
        if ($valid === []) {
            throw ValidationException::withMessages([
                'session_ids' => $sessionIds === []
                    ? "Kategori \"{$ticketType->nama_tiket}\" harus punya minimal 1 sesi. "
                        .'Pilih minimal satu hari/sesi supaya tiketnya bisa dipakai check-in.'
                    : "Sesi yang dipilih untuk kategori \"{$ticketType->nama_tiket}\" tidak ditemukan "
                        ."di event \"{$ticketType->event->nama_event}\". Pilih sesi yang benar.",
            ]);
        }

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
            // 'sisa_kuota' SENGAJA tidak diterima dari request: itu angka
            // turunan (kuota - tiket terpakai) dan hanya boleh diubah oleh
            // alur order + nontix:reconcile-stock. Menerimanya dari klien
            // pernah membuat kuota nyangkut.
            'max_per_order' => ['nullable', 'integer', 'min:1'],
            'jam_masuk_mulai' => ['nullable', 'date_format:H:i'],
            'jam_masuk_selesai' => ['nullable', 'date_format:H:i'],
            'gambar_url' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ]);
    }
}
