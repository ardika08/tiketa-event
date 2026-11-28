<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Models\Event;
use App\Models\FormField;
use App\Models\Order;
use App\Models\Organizer;
use App\Models\Payout;
use App\Models\Ticket;

class ReportService
{
    private function paidOrdersForOrganizer(int $organizerId)
    {
        return Order::query()
            ->where('status', OrderStatus::PAID)
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId));
    }

    public function partnerSummary(int $organizerId): array
    {
        $gross = (float) $this->paidOrdersForOrganizer($organizerId)->sum('total_harga');
        $serviceFee = (float) $this->paidOrdersForOrganizer($organizerId)->sum('biaya_layanan');
        $tickets = Ticket::query()
            ->whereHas('order', function ($q) use ($organizerId) {
                $q->where('status', OrderStatus::PAID)
                    ->whereHas('event', fn ($e) => $e->where('organizer_id', $organizerId));
            })
            ->count();

        $pending = $this->pendingOrdersForOrganizer($organizerId);

        return [
            'tiket_terjual' => $tickets,
            'jumlah_order' => $this->paidOrdersForOrganizer($organizerId)->count(),
            'pendapatan_kotor' => $gross,
            'biaya_layanan' => $serviceFee,
            'pendapatan_bersih' => $gross - $serviceFee,
            'order_pending' => (clone $pending)->count(),
            'nilai_pending' => (float) (clone $pending)->sum('total_harga'),
        ];
    }

    private function pendingOrdersForOrganizer(int $organizerId)
    {
        // Termasuk masa tenggang: order tetap menahan kuota sampai benar-benar
        // dibatalkan (nontix.expiry_grace_minutes), jadi laporan ikut menyesuaikan.
        $grace = max(0, (int) config('nontix.expiry_grace_minutes'));

        return Order::query()
            ->where('status', OrderStatus::PENDING)
            ->where('batas_bayar', '>', now()->subMinutes($grace))
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId));
    }

    /**
     * Rekap penjualan per jenis tiket, hanya dari order lunas.
     */
    public function partnerSales(int $organizerId): array
    {
        // Diskon dihitung per order (voucher bisa 100%), jadi tidak bisa
        // ditempelkan ke satu jenis tiket saja. Karena itu bruto diambil dari
        // item, lalu diskon order dialokasikan proporsional ke tiap jenis tiket.
        // Urutan daftar mengikuti kemunculan pertama, jadi stabil antar request.
        $orders = $this->paidOrdersForOrganizer($organizerId)
            ->with([
                'items:id,order_id,ticket_type_id,jumlah,subtotal',
                'items.ticketType:id,nama_tiket,event_id,harga,kuota,sisa_kuota,status',
                'items.ticketType.event:id,nama_event',
            ])
            ->get();

        $rows = [];
        foreach ($orders as $order) {
            $diskon = min((float) $order->diskon, (float) $order->subtotal);
            $orderBruto = (float) $order->items->sum(fn ($item) => (float) $item->subtotal);
            $reduction = $orderBruto > 0 ? $diskon / $orderBruto : 0.0;

            foreach ($order->items as $item) {
                $type = $item->ticketType;
                if (! $type) {
                    continue;
                }

                $id = (int) $type->id;
                if (! isset($rows[$id])) {
                    $rows[$id] = [
                        'id' => $id,
                        'event' => $type->event?->nama_event,
                        'nama_tiket' => $type->nama_tiket,
                        'harga' => (float) $type->harga,
                        'kuota' => (int) $type->kuota,
                        'sisa_kuota' => (int) $type->sisa_kuota,
                        'terjual' => 0,
                        'pendapatan' => 0.0,
                        'diskon' => 0.0,
                        'status' => $type->status,
                        'harga_satuan_rata_rata' => 0.0,
                    ];
                }

                $bruto = (float) $item->subtotal;
                $potongan = $bruto * $reduction;

                $rows[$id]['terjual'] += (int) $item->jumlah;
                $rows[$id]['pendapatan'] += $bruto - $potongan;
                $rows[$id]['diskon'] += $potongan;
            }
        }

        $rows = array_values($rows);
        foreach ($rows as $index => $row) {
            $rows[$index]['pendapatan'] = round($row['pendapatan'], 2);
            $rows[$index]['diskon'] = round($row['diskon'], 2);
            // Harga yang benar-benar dibayar per tiket — pembeda paling jelas
            // untuk tiket yang sebagian terjual lewat voucher diskon.
            $rows[$index]['harga_satuan_rata_rata'] = $row['terjual'] > 0
                ? round($rows[$index]['pendapatan'] / $row['terjual'], 2)
                : 0.0;
        }

        $totalPendapatan = (float) array_sum(array_column($rows, 'pendapatan'));

        return [
            'summary' => [
                'total_terjual' => (int) array_sum(array_column($rows, 'terjual')),
                'total_pendapatan' => round($totalPendapatan, 2),
                'total_diskon' => round((float) array_sum(array_column($rows, 'diskon')), 2),
                'total_jenis_tiket' => count($rows),
            ],
            'data' => $rows,
        ];
    }

    /**
     * Ringkasan saldo pencairan partner.
     */
    public function partnerPayouts(int $organizerId): array
    {
        $summary = $this->partnerSummary($organizerId);
        $bersih = (float) $summary['pendapatan_bersih'];

        $payouts = Payout::where('organizer_id', $organizerId)->latest()->get();

        $diproses = $payouts->whereIn('status', [PayoutStatus::DIAJUKAN, PayoutStatus::DIPROSES])->sum('jumlah');
        $dicairkan = $payouts->where('status', PayoutStatus::SELESAI)->sum('jumlah');

        return [
            'ringkasan' => [
                'total_bersih' => $bersih,
                'sedang_diproses' => (float) $diproses,
                'sudah_dicairkan' => (float) $dicairkan,
                'saldo_tersedia' => max(0.0, $bersih - (float) $diproses - (float) $dicairkan),
                'min_payout' => (float) config('nontix.min_payout'),
            ],
            'data' => $payouts->map(fn (Payout $p) => [
                'id' => $p->id,
                'jumlah' => (float) $p->jumlah,
                'status' => $p->status->value,
                'nama_bank' => $p->nama_bank,
                'nomor_rekening' => $p->nomor_rekening,
                'nama_rekening' => $p->nama_rekening,
                'catatan' => $p->catatan,
                'catatan_admin' => $p->catatan_admin,
                'bukti_transfer_url' => $p->bukti_transfer_url,
                'diajukan_at' => $p->created_at?->toIso8601String(),
                'selesai_at' => $p->selesai_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function partnerSalesTrend(int $organizerId, int $days = 7): array
    {
        $rows = $this->paidOrdersForOrganizer($organizerId)
            ->where('paid_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('DATE(paid_at) as tanggal, COUNT(*) as order_count, SUM(total_harga) as pendapatan')
            ->groupBy('tanggal')
            ->orderBy('tanggal')
            ->get()
            ->keyBy('tanggal');

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $rows->get($date);
            $out[] = [
                'tanggal' => $date,
                'order' => (int) ($row->order_count ?? 0),
                'pendapatan' => (float) ($row->pendapatan ?? 0),
            ];
        }

        return $out;
    }

    public function partnerEvents(int $organizerId): array
    {
        return Event::query()
            ->where('organizer_id', $organizerId)
            ->withCount('ticketTypes')
            ->withSum(['orders as pendapatan' => fn ($q) => $q->where('status', OrderStatus::PAID)], 'total_harga')
            ->withCount(['orders as order_lunas' => fn ($q) => $q->where('status', OrderStatus::PAID)])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Event $event) => [
                'id' => $event->id,
                'nama_event' => $event->nama_event,
                'slug' => $event->slug,
                'status' => $event->status,
                'jumlah_jenis_tiket' => $event->ticket_types_count,
                'order_lunas' => $event->order_lunas,
                'pendapatan' => (float) ($event->pendapatan ?? 0),
            ])
            ->all();
    }

    /**
     * Semua pembeli dari order milik organiser (semua status).
     * Status pending/kadaluarsa/dibatalkan tetap tampil agar partner bisa
     * menindaklanjuti; pendapatan tetap hanya dihitung dari order lunas.
     */
    public function partnerBuyers(int $organizerId): array
    {
        return Order::query()
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId))
            ->with([
                'event:id,nama_event',
                'items.ticketType:id,nama_tiket',
                'tickets:id,order_id,kode_tiket,status_kehadiran',
                'pembuat:id,nama',
            ])
            // Hanya field formulir milik event tersebut; jawaban mentah di
            // form_data tidak ikut diekspor mentah-mentah.
            ->with(['event.formFields' => fn ($fields) => $fields
                ->where('status', 'aktif')
                ->where(fn ($scoped) => $scoped->whereNull('ticket_type_ids')->orWhereJsonLength('ticket_type_ids', '>', 0))])
            ->withCount(['tickets as jumlah_tiket_dibatalkan' => fn ($tickets) => $tickets
                ->whereHas('passes', fn ($passes) => $passes->whereNotNull('cancelled_at'))])
            ->latest('created_at')
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'kode_order' => $order->kode_order,
                'nama' => $order->nama_pembeli,
                'email' => $order->email,
                'whatsapp' => $order->whatsapp,
                'instagram' => $order->instagram,
                'tiktok' => $order->tiktok,
                'threads' => $order->threads,
                'form_data' => $order->form_data,
                'form_export' => $this->formExport($order),
                'ticket_names' => $order->items->mapWithKeys(
                    fn ($i) => [(string) $i->ticket_type_id => $i->ticketType?->nama_tiket]
                )->all(),
                'event' => $order->event?->nama_event,
                'tiket' => $order->items->map(fn ($i) => $i->ticketType?->nama_tiket.' x'.$i->jumlah)->implode(', '),
                'jumlah' => $order->items->sum('jumlah'),
                'jumlah_tiket_dibatalkan' => (int) $order->jumlah_tiket_dibatalkan,
                'total' => (float) $order->total_harga,
                'status' => $order->status,
                // online = lewat gateway, manual = diinput partner (OTS/tunai/transfer).
                'sumber' => $order->sumber ?? Order::SUMBER_ONLINE,
                'metode_bayar' => $order->metode_bayar,
                'catatan_manual' => $order->catatan_manual,
                'dibuat_oleh' => $order->pembuat?->nama,
                'batas_bayar' => $order->batas_bayar?->toIso8601String(),
                'tanggal' => ($order->paid_at ?? $order->created_at)?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Jawaban formulir yang aman diekspor: hanya label field yang terdaftar
     * untuk event ini dan jenis tiket yang benar-benar dibeli. Bentuknya daftar
     * {label, ticket_id, value} supaya jenis tiket menjadi identitas kolom,
     * bukan urutan kemunculan di form_data.
     *
     * @return list<array{label: string, ticket_id: int|null, value: string}>
     */
    private function formExport(Order $order): array
    {
        $raw = is_array($order->form_data) ? $order->form_data : [];
        $purchased = $order->items->pluck('ticket_type_id')->map(fn ($id) => (int) $id)->unique()->all();
        $fields = FormField::query()
            ->where('event_id', $order->event_id)
            ->where('status', 'aktif')
            ->where(fn ($scoped) => $scoped->whereNull('ticket_type_ids')->orWhereJsonLength('ticket_type_ids', '>', 0))
            ->orderBy('urutan')->orderBy('id')
            ->get();

        $answers = [];
        foreach ($fields as $field) {
            $appliesToAll = $field->ticket_type_ids === null && $field->ticket_type_id === null;
            $targets = $field->targetTicketIds();
            if (! $appliesToAll && $targets === []) {
                continue;
            }

            if ($appliesToAll) {
                $value = $this->scalarAnswer($this->formAnswer($field, $raw['order'] ?? []));
                if ($value !== null) {
                    $answers[] = ['label' => $field->label, 'ticket_id' => null, 'value' => $value];
                }

                continue;
            }

            foreach (array_values(array_intersect($purchased, $targets)) as $ticketId) {
                $value = $this->scalarAnswer($this->formAnswer($field, $raw['tickets'][$ticketId] ?? []));
                if ($value !== null) {
                    $answers[] = ['label' => $field->label, 'ticket_id' => $ticketId, 'value' => $value];
                }
            }
        }

        return $answers;
    }

    /** Samakan cara membaca jawaban dengan validasi checkout. */
    private function formAnswer(FormField $field, mixed $answers): mixed
    {
        if (! is_array($answers)) {
            return null;
        }

        return $answers[$field->label]
            ?? $answers[$field->key ?? '']
            ?? $answers[strtolower($field->key ?: $field->label)]
            ?? $answers[strtolower($field->label)]
            ?? null;
    }

    private function scalarAnswer(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    public function partnerFinance(int $organizerId): array
    {
        $orders = $this->paidOrdersForOrganizer($organizerId)
            ->with(['event:id,nama_event', 'items'])
            ->latest('paid_at')
            ->get()
            ->map(fn (Order $order) => [
                'kode_order' => $order->kode_order,
                'event' => $order->event?->nama_event,
                'tanggal' => $order->paid_at?->toIso8601String(),
                'jumlah_tiket' => (int) $order->items->sum('jumlah'),
                'bruto' => (float) $order->total_harga,
                'biaya_layanan' => (float) $order->biaya_layanan,
                'netto' => (float) $order->total_harga - (float) $order->biaya_layanan,
                // Kolom laporan bergaya mutasi bank: pembeli, sumber, metode.
                'nama_pembeli' => $order->nama_pembeli,
                'email' => $order->email,
                'sumber' => $order->sumber ?: Order::SUMBER_ONLINE,
                'metode_bayar' => $order->metode_bayar,
                'jenis' => $order->isManual() ? 'penjualan_manual' : 'penjualan_online',
            ])
            ->all();

        return [
            'summary' => $this->partnerSummary($organizerId),
            'transaksi' => $orders,
            'payout' => $this->partnerPayouts($organizerId),
        ];
    }

    public function adminSummary(): array
    {
        $pending = Order::where('status', OrderStatus::PENDING)
            ->where('batas_bayar', '>', now()->subMinutes(max(0, (int) config('nontix.expiry_grace_minutes'))));

        return [
            'total_mitra' => Organizer::count(),
            'mitra_terverifikasi' => Organizer::where('status_verifikasi', 'terverifikasi')->count(),
            'mitra_pending' => Organizer::where('status_verifikasi', 'pending')->count(),
            'total_event' => Event::count(),
            'event_aktif' => Event::where('status', 'aktif')->count(),
            'tiket_terjual' => Ticket::whereHas('order', fn ($q) => $q->where('status', OrderStatus::PAID))->count(),
            'transaksi_lunas' => Order::where('status', OrderStatus::PAID)->count(),
            'transaksi_pending' => (clone $pending)->count(),
            'nilai_pending' => (float) (clone $pending)->sum('total_harga'),
            'pendapatan_platform' => (float) Order::where('status', OrderStatus::PAID)->sum('biaya_layanan'),
        ];
    }

    public function adminPayouts(): array
    {
        return Payout::query()
            ->with('organizer:id,nama_penyelenggara,email')
            ->latest()
            ->get()
            ->map(fn (Payout $p) => [
                'id' => $p->id,
                'organizer_id' => $p->organizer_id,
                'mitra' => $p->organizer?->nama_penyelenggara,
                'email' => $p->organizer?->email,
                'jumlah' => (float) $p->jumlah,
                'status' => $p->status->value,
                'nama_bank' => $p->nama_bank,
                'nomor_rekening' => $p->nomor_rekening,
                'nama_rekening' => $p->nama_rekening,
                'catatan' => $p->catatan,
                'catatan_admin' => $p->catatan_admin,
                'bukti_transfer_url' => $p->bukti_transfer_url,
                'diajukan_at' => $p->created_at?->toIso8601String(),
                'selesai_at' => $p->selesai_at?->toIso8601String(),
            ])
            ->all();
    }

    public function adminRevenueTrend(int $days = 7): array
    {
        $rows = Order::where('status', OrderStatus::PAID)
            ->where('paid_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('DATE(paid_at) as tanggal, COUNT(*) as transaksi, SUM(biaya_layanan) as pendapatan')
            ->groupBy('tanggal')
            ->orderBy('tanggal')
            ->get()
            ->keyBy('tanggal');

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $rows->get($date);
            $out[] = [
                'tanggal' => $date,
                'transaksi' => (int) ($row->transaksi ?? 0),
                'pendapatan' => (float) ($row->pendapatan ?? 0),
            ];
        }

        return $out;
    }

    public function adminPartners(): array
    {
        return Organizer::query()
            ->withCount('events')
            ->with(['user:id,nama,email'])
            ->get()
            ->map(fn (Organizer $org) => [
                'id' => $org->id,
                'nama_penyelenggara' => $org->nama_penyelenggara,
                'email' => $org->email ?? $org->user?->email,
                'telepon' => $org->telepon,
                'status_verifikasi' => $org->status_verifikasi,
                'status_akun' => $org->status_akun,
                'jumlah_event' => $org->events_count,
                'pendapatan_platform' => (float) Order::where('status', OrderStatus::PAID)
                    ->whereHas('event', fn ($q) => $q->where('organizer_id', $org->id))
                    ->sum('biaya_layanan'),
            ])
            ->all();
    }

    public function adminEvents(): array
    {
        return Event::query()
            ->with('organizer:id,nama_penyelenggara')
            ->withSum(['orders as pendapatan' => fn ($q) => $q->where('status', OrderStatus::PAID)], 'total_harga')
            ->withCount(['orders as order_lunas' => fn ($q) => $q->where('status', OrderStatus::PAID)])
            ->latest('created_at')
            ->get()
            ->map(fn (Event $event) => [
                'id' => $event->id,
                'nama_event' => $event->nama_event,
                'slug' => $event->slug,
                'organizer_id' => $event->organizer_id,
                'organizer' => $event->organizer?->nama_penyelenggara,
                'status' => $event->status,
                'order_lunas' => $event->order_lunas,
                'pendapatan' => (float) ($event->pendapatan ?? 0),
            ])
            ->all();
    }
}
