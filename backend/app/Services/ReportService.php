<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organizer;
use App\Models\Payout;
use App\Models\Ticket;
use App\Models\TicketType;

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
        return Order::query()
            ->where('status', OrderStatus::PENDING)
            ->where('batas_bayar', '>', now())
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId));
    }

    /**
     * Rekap penjualan per jenis tiket, hanya dari order lunas.
     */
    public function partnerSales(int $organizerId): array
    {
        $ticketTypes = TicketType::query()
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId))
            ->with(['event:id,nama_event'])
            ->withSum(['orderItems as terjual' => function ($q) {
                $q->whereHas('order', fn ($o) => $o->where('status', OrderStatus::PAID));
            }], 'jumlah')
            ->withSum(['orderItems as pendapatan' => function ($q) {
                $q->whereHas('order', fn ($o) => $o->where('status', OrderStatus::PAID));
            }], 'subtotal')
            ->orderByDesc('event_id')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'event' => $t->event?->nama_event,
                'nama_tiket' => $t->nama_tiket,
                'harga' => (float) $t->harga,
                'kuota' => (int) $t->kuota,
                'sisa_kuota' => (int) $t->sisa_kuota,
                'terjual' => (int) ($t->terjual ?? 0),
                'pendapatan' => (float) ($t->pendapatan ?? 0),
                'status' => $t->status,
            ])
            ->all();

        return [
            'summary' => [
                'total_terjual' => (int) array_sum(array_column($ticketTypes, 'terjual')),
                'total_pendapatan' => (float) array_sum(array_column($ticketTypes, 'pendapatan')),
                'total_jenis_tiket' => count($ticketTypes),
            ],
            'data' => $ticketTypes,
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
            ->with(['event:id,nama_event', 'items.ticketType:id,nama_tiket', 'tickets:id,order_id,kode_tiket,status_kehadiran'])
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
                'ticket_names' => $order->items->mapWithKeys(
                    fn ($i) => [(string) $i->ticket_type_id => $i->ticketType?->nama_tiket]
                )->all(),
                'event' => $order->event?->nama_event,
                'tiket' => $order->items->map(fn ($i) => $i->ticketType?->nama_tiket.' x'.$i->jumlah)->implode(', '),
                'jumlah' => $order->items->sum('jumlah'),
                'total' => (float) $order->total_harga,
                'status' => $order->status,
                'batas_bayar' => $order->batas_bayar?->toIso8601String(),
                'tanggal' => ($order->paid_at ?? $order->created_at)?->toIso8601String(),
            ])
            ->all();
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
            ->where('batas_bayar', '>', now());

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
