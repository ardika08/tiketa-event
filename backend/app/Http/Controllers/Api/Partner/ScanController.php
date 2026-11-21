<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Gate;
use App\Models\Staff;
use App\Models\TicketType;
use App\Services\CheckinService;
use App\Support\JadwalMasuk;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ScanController extends PartnerController
{
    /**
     * Data halaman Scan: daftar sesi + jadwal gelombang kategori tiket.
     *
     * Digabung dalam satu endpoint supaya halaman scan cukup sekali muat, dan
     * supaya aturan waktu yang dipakai backend (App\Support\JadwalMasuk) sama
     * persis dengan yang ditampilkan ke petugas — panel jadwal dan penolakan di
     * gerbang tidak mungkin berbeda.
     *
     * Sesi dipilih otomatis dari jendela manual yang terbuka atau sesi yang
     * menerima check-in bawaan. Tanggal acara tetap tidak berubah.
     */
    public function sessions(Request $request, Event $event)
    {
        $this->authorizeEvent($request, $event);

        $sekarang = now();

        $sessions = $event->sessions()
            ->with(['ticketTypes' => fn ($q) => $q->where('ticket_types.event_id', $event->id)->orderBy('nama_tiket')])
            ->withCount([
                'ticketPasses as total_pass',
                'ticketPasses as total_hadir' => fn ($q) => $q->where('status_kehadiran', 'hadir'),
            ])
            ->get();

        $sesiBerjalan = $sessions->first(
            fn (EventSession $s) => JadwalMasuk::statusSesi($s->tanggal_mulai, $s->tanggal_selesai, $sekarang) === 'berjalan'
        );
        $sesiBawaan = $sesiBerjalan ?? $sessions->first();

        $jadwal = collect();
        $tersedia = [];
        $manualTerbuka = [];
        $adaJadwalManual = false;
        foreach ($sessions as $session) {
            $statusSesi = JadwalMasuk::statusTanggal($session->tanggal_mulai, $session->tanggal_selesai, $sekarang);
            $tersedia[$session->id] = false;

            foreach ($session->ticketTypes as $ticketType) {
                $mulai = $ticketType->pivot->checkin_mulai;
                $selesai = $ticketType->pivot->checkin_selesai;
                $manual = JadwalMasuk::statusCheckinManual($mulai, $selesai, $sekarang);
                $jadwal->push([
                    'session_id' => $session->id,
                    'ticket_type_id' => $ticketType->id,
                    'nama_tiket' => $ticketType->nama_tiket,
                    'checkin_mulai' => JadwalMasuk::waktuCheckin($mulai)?->format(JadwalMasuk::FORMAT_CHECKIN),
                    'checkin_selesai' => JadwalMasuk::waktuCheckin($selesai)?->format(JadwalMasuk::FORMAT_CHECKIN),
                ]);

                $adaJadwalManual = $adaJadwalManual || $manual !== null;
                if ($manual === 'dalam') {
                    $manualTerbuka[$session->id] = $tersedia[$session->id] = true;
                } elseif ($manual === null && ($statusSesi === 'dalam' || ($statusSesi === null && $session->id === $sesiBawaan?->id))
                    && $this->jamKategoriTerbuka($ticketType, $sekarang)) {
                    $tersedia[$session->id] = true;
                }
            }
        }

        // Sales assignment changes do not change the session encoded in an issued QR.
        $sesiPerluCek = $sessions->filter(function (EventSession $s) use ($tersedia, $sesiBawaan, $sekarang) {
            $status = JadwalMasuk::statusTanggal($s->tanggal_mulai, $s->tanggal_selesai, $sekarang);

            return ! $tersedia[$s->id] && ($status === 'dalam' || ($status === null && $s->id === $sesiBawaan?->id));
        })->modelKeys();
        $tanpaSesiTerbuka = false;
        if ($sesiPerluCek || ($sessions->isNotEmpty() && ! in_array(true, $tersedia, true))) {
            $terbit = DB::table('ticket_passes as pass')
                ->join('tickets as tiket', 'tiket.id', '=', 'pass.ticket_id')
                ->join('orders as pesanan', 'pesanan.id', '=', 'tiket.order_id')
                ->join('ticket_types as jenis', 'jenis.id', '=', 'tiket.ticket_type_id')
                ->leftJoin('event_session_ticket_type as pasangan', function ($join) {
                    $join->on('pasangan.event_session_id', '=', 'pass.event_session_id')
                        ->on('pasangan.ticket_type_id', '=', 'tiket.ticket_type_id');
                })
                ->whereNull('pasangan.id')
                ->where(fn ($q) => $q->whereIn('pass.event_session_id', $sesiPerluCek)->orWhereNull('pass.event_session_id'))
                ->where('pesanan.event_id', $event->id)
                ->where('pesanan.status', \App\Enums\OrderStatus::PAID->value)
                ->distinct()
                ->get(['pass.event_session_id', 'jenis.jam_masuk_mulai', 'jenis.jam_masuk_selesai']);
            foreach ($terbit as $row) {
                $jam = JadwalMasuk::statusJam(JadwalMasuk::jamBukaEfektif($row->jam_masuk_mulai), $row->jam_masuk_selesai, $sekarang);
                if ($jam === null || $jam === 'dalam') {
                    if ($row->event_session_id === null) {
                        $tanpaSesiTerbuka = true;
                    } else {
                        $tersedia[$row->event_session_id] = true;
                    }
                }
            }
        }
        $sesiAktif = $sessions->first(fn (EventSession $s) => isset($manualTerbuka[$s->id]))
            ?? ($sesiBerjalan && $tersedia[$sesiBerjalan->id] ? $sesiBerjalan : null)
            ?? $sessions->first(fn (EventSession $s) => $tersedia[$s->id])
            ?? $sesiBerjalan
            ?? $sessions->first();
        if (! $adaJadwalManual) {
            $sesiAktif = $sesiBerjalan ?? $sessions->first();
        }
        $kategoriAktif = $sesiAktif?->ticketTypes->keyBy('id') ?? collect();

        // Jumlah hadir per kategori dihitung untuk SESI AKTIF saja — itulah yang
        // dibutuhkan petugas di gerbang ("berapa dari tiket gelombang ini yang
        // sudah masuk"), bukan angka sepanjang event.
        $hitung = collect();

        if ($sesiAktif) {
            $hitung = DB::table('ticket_passes')
                ->join('tickets', 'tickets.id', '=', 'ticket_passes.ticket_id')
                ->where('ticket_passes.event_session_id', $sesiAktif->id)
                ->groupBy('tickets.ticket_type_id')
                ->selectRaw(
                    'tickets.ticket_type_id as ticket_type_id, COUNT(*) as total_pass, SUM(CASE WHEN ticket_passes.status_kehadiran = ? THEN 1 ELSE 0 END) as total_hadir',
                    ['hadir'],
                )
                ->get()
                ->keyBy('ticket_type_id');
        }

        $jenisTiket = $event->ticketTypes()
            ->orderByRaw('jam_masuk_mulai is null')
            ->orderBy('jam_masuk_mulai')
            ->orderBy('nama_tiket')
            ->get(['id', 'nama_tiket', 'jam_masuk_mulai', 'jam_masuk_selesai']);
        $adaJadwalSesi = $sessions->contains(fn (EventSession $s) => $s->tanggal_mulai !== null || $s->tanggal_selesai !== null);
        $kategori = $jenisTiket
            ->map(function (TicketType $t) use ($sekarang, $hitung, $sesiAktif, $kategoriAktif) {
                $angka = $hitung->get($t->id);
                $pivot = $kategoriAktif->get($t->id)?->pivot;
                $mulai = $pivot?->checkin_mulai;
                $selesai = $pivot?->checkin_selesai;
                $manual = JadwalMasuk::statusCheckinManual($mulai, $selesai, $sekarang);
                $buka = JadwalMasuk::waktuCheckin($mulai);

                return [
                    'id' => $t->id,
                    'nama_tiket' => $t->nama_tiket,
                    'jam_masuk_mulai' => JadwalMasuk::jam($t->jam_masuk_mulai) ?: null,
                    'jam_masuk_selesai' => JadwalMasuk::jam($t->jam_masuk_selesai) ?: null,
                    'label_jam' => JadwalMasuk::labelJam($t->jam_masuk_mulai, $t->jam_masuk_selesai),
                    // Jendela yang benar-benar ditegakkan gerbang (sudah digeser
                    // "buka lebih awal"). Dipakai layar petugas supaya badge
                    // hijau selalu cocok dengan jam yang tertulis di baris itu.
                    'label_gerbang' => JadwalMasuk::labelJam(
                        $manual !== null ? $buka->format('H:i') : JadwalMasuk::jamBukaEfektif($t->jam_masuk_mulai),
                        $manual !== null ? JadwalMasuk::waktuCheckin($selesai)->format('H:i') : $t->jam_masuk_selesai,
                    ),
                    // Konteks hari sesi dikirim ikut: di luar hari-H semua
                    // kategori harus terbaca "belum dibuka", bukan "sudah
                    // ditutup" hanya karena jamnya sudah lewat hari ini.
                    'status' => match ($manual) {
                        'belum' => 'belum_dibuka',
                        'lewat' => 'sudah_ditutup',
                        'dalam' => 'dibuka',
                        default => JadwalMasuk::statusKategori(
                            $t->jam_masuk_mulai,
                            $t->jam_masuk_selesai,
                            $sekarang,
                            $sesiAktif?->tanggal_mulai,
                            $sesiAktif?->tanggal_selesai,
                        ),
                    },
                    'menit_ke_buka' => match ($manual) {
                        'belum' => (int) ceil($sekarang->diffInSeconds($buka) / 60),
                        null => JadwalMasuk::menitKeBukaKategori($t->jam_masuk_mulai, $sekarang, $sesiAktif?->tanggal_mulai),
                        default => null,
                    },
                    'total_pass' => (int) ($angka->total_pass ?? 0),
                    'total_hadir' => (int) ($angka->total_hadir ?? 0),
                ];
            })
            ->values();

        return response()->json([
            'data' => $sessions->map(fn (EventSession $session) => [
                'id' => $session->id,
                'label' => $session->label,
                'nama_session' => $session->nama_session,
                'tanggal_mulai' => $session->tanggal_mulai?->toIso8601String(),
                'tanggal_selesai' => $session->tanggal_selesai?->toIso8601String(),
                'total_pass' => $session->total_pass,
                'total_hadir' => $session->total_hadir,
                'status_waktu' => JadwalMasuk::statusSesi($session->tanggal_mulai, $session->tanggal_selesai, $sekarang),
                'scan_tersedia' => $tersedia[$session->id],
            ])->values(),
            'kategori' => $kategori,
            'jadwal_checkin' => $jadwal,
            'scan_tersedia' => $sessions->isEmpty() || $tanpaSesiTerbuka || in_array(true, $tersedia, true),
            'sesi_aktif_id' => $sesiAktif?->id,
            'sesi_bawaan_id' => $sesiBawaan?->id,
            'ada_jadwal_sesi' => $adaJadwalSesi,
            'sekarang' => $sekarang->toIso8601String(),
            'buka_lebih_awal_menit' => JadwalMasuk::menitBukaLebihAwal(),
        ]);
    }

    public function updateSchedule(Request $request, Event $event)
    {
        $this->authorizeEvent($request, $event);

        $format = JadwalMasuk::FORMAT_CHECKIN;
        $data = $request->validate([
            'session_id' => ['required', 'integer'],
            'ticket_type_id' => ['required', 'integer'],
            'checkin_mulai' => ['present', 'nullable', 'required_with:checkin_selesai', 'date_format:'.$format],
            'checkin_selesai' => ['present', 'nullable', 'required_with:checkin_mulai', 'date_format:'.$format, 'after:checkin_mulai'],
        ]);

        $session = $event->sessions()->findOrFail($data['session_id']);
        $ticketTypes = $session->ticketTypes();
        $ticketTypes->where('ticket_types.event_id', $event->id)->findOrFail($data['ticket_type_id']);

        $ticketTypes->updateExistingPivot($data['ticket_type_id'], [
            'checkin_mulai' => JadwalMasuk::waktuCheckin($data['checkin_mulai'])?->toDateTimeString(),
            'checkin_selesai' => JadwalMasuk::waktuCheckin($data['checkin_selesai'])?->toDateTimeString(),
        ]);

        return response()->json(['message' => 'Jadwal check-in tersimpan.']);
    }

    private function jamKategoriTerbuka(TicketType $ticketType, CarbonInterface $sekarang): bool
    {
        return in_array(JadwalMasuk::statusJam(
            JadwalMasuk::jamBukaEfektif($ticketType->jam_masuk_mulai),
            $ticketType->jam_masuk_selesai,
            $sekarang,
        ), [null, 'dalam'], true);
    }

    public function scan(Request $request, CheckinService $service)
    {
        $data = $request->validate([
            'event_id' => ['required', 'exists:events,id'],
            // Sesi TIDAK wajib lagi: sesi pencatatan diambil dari QR itu sendiri
            // (lihat CheckinService::scan). Parameter ini hanya cadangan untuk
            // event yang tanggal sesinya belum diisi.
            'session_id' => ['nullable', 'exists:event_sessions,id'],
            'kode_qr' => ['required', 'string'],
            // Gate & staff wajib milik organizer ini. Catatan: tabel staff
            // bernama `staff` (tunggal) — "staff" dianggap kata tak berbilang,
            // jadi `exists:staffs,id` akan mencari tabel yang tidak ada.
            'gate_id' => ['nullable', Rule::exists('gates', 'id')->where('organizer_id', $this->organizerId($request))],
            'staff_id' => ['nullable', Rule::exists('staff', 'id')->where('organizer_id', $this->organizerId($request))],
        ], [
            'gate_id.exists' => 'Gate itu bukan milikmu.',
            'staff_id.exists' => 'Staff itu bukan milikmu.',
        ]);

        $event = Event::where('organizer_id', $this->organizerId($request))->findOrFail($data['event_id']);
        $session = isset($data['session_id'])
            ? EventSession::where('event_id', $event->id)->find($data['session_id'])
            : null;
        $gate = isset($data['gate_id']) ? Gate::find($data['gate_id']) : null;
        $staff = isset($data['staff_id']) ? Staff::find($data['staff_id']) : null;

        $result = $service->scan($event, $session, $data['kode_qr'], $gate, $staff);

        $pass = $result['pass'] ?? null;
        unset($result['pass']);

        $kategori = $pass?->ticket?->ticketType;

        return response()->json($result + [
            'sekarang' => now()->format('H:i'),
            'ticket' => $pass ? [
                'kode_tiket' => $pass->ticket?->kode_tiket,
                'nama_pemegang' => $pass->ticket?->nama_pemegang,
                'nama_tiket' => $kategori?->nama_tiket,
                'jam_masuk_mulai' => $kategori ? (JadwalMasuk::jam($kategori->jam_masuk_mulai) ?: null) : null,
                'jam_masuk_selesai' => $kategori ? (JadwalMasuk::jam($kategori->jam_masuk_selesai) ?: null) : null,
                'label_jam' => $kategori ? JadwalMasuk::labelJam($kategori->jam_masuk_mulai, $kategori->jam_masuk_selesai) : null,
                'sesi_label' => $pass->session?->label,
                'sesi_nama' => $pass->session?->nama_session,
            ] : null,
        ]);
    }

    public function attendance(Request $request)
    {
        $data = $request->validate([
            'event_id' => ['required', 'exists:events,id'],
            'session_id' => ['nullable', 'exists:event_sessions,id'],
        ]);

        $event = Event::where('organizer_id', $this->organizerId($request))->findOrFail($data['event_id']);

        $sessions = $event->sessions()
            ->withCount([
                'ticketPasses as total_pass',
                'ticketPasses as total_hadir' => fn ($q) => $q->where('status_kehadiran', 'hadir'),
            ])
            ->get()
            ->map(fn (EventSession $session) => [
                'id' => $session->id,
                'label' => $session->label,
                'nama_session' => $session->nama_session,
                'total_pass' => $session->total_pass,
                'total_hadir' => $session->total_hadir,
                'persentase' => $session->total_pass > 0
                    ? round(($session->total_hadir / $session->total_pass) * 100)
                    : 0,
            ]);

        // Rekap kehadiran per KATEGORI tiket. Populasi = ticket_passes yang sudah
        // terbit (hanya dibuat untuk order lunas, lihat OrderService::generateTickets),
        // jadi totalnya konsisten dengan angka "Total Tiket" di kartu statistik.
        // Kategori yang belum ada penjualan tetap dikirim dengan angka 0 supaya
        // daftarnya tidak pernah "menghilang" dari mata partner.
        $kategori = TicketType::query()
            ->where('event_id', $event->id)
            ->orderBy('nama_tiket')
            ->get(['id', 'nama_tiket'])
            ->mapWithKeys(fn (TicketType $ticketType) => [
                $ticketType->id => [
                    'id' => $ticketType->id,
                    'nama_tiket' => $ticketType->nama_tiket,
                    'total_pass' => 0,
                    'total_hadir' => 0,
                    'persentase' => 0,
                    // Rincian per sesi; kunci = event_session_id ("tanpa_sesi" bila null).
                    'per_sesi' => [],
                ],
            ])
            ->all();

        // Satu query agregat untuk semua kombinasi kategori x sesi (anti N+1).
        $perKategoriSesi = DB::table('ticket_passes')
            ->join('tickets', 'tickets.id', '=', 'ticket_passes.ticket_id')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->where('orders.event_id', $event->id)
            ->groupBy('tickets.ticket_type_id', 'ticket_passes.event_session_id')
            ->selectRaw(
                'tickets.ticket_type_id, ticket_passes.event_session_id, '
                .'COUNT(*) as total_pass, '
                .'SUM(CASE WHEN ticket_passes.status_kehadiran = ? THEN 1 ELSE 0 END) as total_hadir',
                ['hadir'],
            )
            ->get();

        foreach ($perKategoriSesi as $baris) {
            $id = (int) $baris->ticket_type_id;

            if (! isset($kategori[$id])) {
                continue; // kategori milik event lain / sudah dihapus
            }

            $kunci = $baris->event_session_id === null ? 'tanpa_sesi' : (string) $baris->event_session_id;
            $pass = (int) $baris->total_pass;
            $hadir = (int) $baris->total_hadir;

            $kategori[$id]['total_pass'] += $pass;
            $kategori[$id]['total_hadir'] += $hadir;
            $kategori[$id]['per_sesi'][$kunci] = [
                'total_pass' => $pass,
                'total_hadir' => $hadir,
            ];
        }

        $kategori = array_values(array_map(function (array $baris) {
            $baris['persentase'] = $baris['total_pass'] > 0
                ? (int) round(($baris['total_hadir'] / $baris['total_pass']) * 100)
                : 0;

            return $baris;
        }, $kategori));

        $checkins = Checkin::where('event_id', $event->id)
            ->when($data['session_id'] ?? null, fn ($q, $v) => $q->where('event_session_id', $v))
            ->with(['session:id,label,nama_session', 'gate:id,nama_gate', 'staff:id,nama,peran'])
            ->latest('checked_in_at')
            ->get();

        return response()->json([
            'sessions' => $sessions,
            'kategori' => $kategori,
            'checkins' => $checkins,
        ]);
    }
}
