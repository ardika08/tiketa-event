<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketCancellation;
use App\Models\TicketCancellationNotification;
use App\Models\TicketPass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketCancellationService
{
    private const TICKET_RELATIONS = ['orderItem', 'ticketType'];

    public function search(Event $event, ?string $query): array
    {
        $matchOrder = fn ($orders) => $orders->when($query !== null && $query !== '', fn ($orders) => $orders->where(fn ($match) => $match
            ->whereLike('kode_order', '%'.$query.'%')
            ->orWhereLike('nama_pembeli', '%'.$query.'%')
            ->orWhereLike('email', '%'.$query.'%')));
        $orders = $matchOrder($event->orders())
            ->with(['tickets' => fn ($tickets) => $tickets->orderBy('id')->with([...self::TICKET_RELATIONS, 'passes.session'])])
            ->latest('id')->limit(20)->get()->map(function (Order $order) {
                return [
                    'id' => $order->id,
                    'kode_order' => $order->kode_order,
                    'nama' => $order->nama_pembeli,
                    'email' => $order->email,
                    'tickets' => $order->tickets->map(function (Ticket $ticket) use ($order) {
                        $status = $this->status($order, $ticket, $ticket->passes);

                        return $this->describe($ticket, $ticket->passes) + [
                            'eligible' => $status === 'eligible',
                            'status' => $status,
                        ];
                    })->values()->all(),
                ];
            })->all();

        $notifications = TicketCancellationNotification::query()
            ->whereHas('cancellation.order', fn ($order) => $matchOrder($order->where('event_id', $event->id)))
            ->with('cancellation.order:id,kode_order')
            ->orderByRaw("CASE WHEN status IN ('failed', 'uncertain') THEN 0 ELSE 1 END")
            ->latest('id')->limit(50)->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'kode_order' => $notification->cancellation->order->kode_order,
                'status' => $notification->status === 'sending' ? 'pending' : $notification->status,
            ])->all();

        return compact('orders', 'notifications');
    }

    public function cancel(Event $event, int $actorId, array $selection, string $reason, ?string $note, string $requestId): array
    {
        $ids = array_map('intval', $selection['ticket_ids']);
        sort($ids, SORT_NUMERIC);
        $key = hash('sha256', $requestId);
        $fingerprint = hash('sha256', json_encode([$ids, $reason, $note], JSON_THROW_ON_ERROR));
        $orderId = (int) $selection['order_id'];

        return DB::transaction(function () use ($event, $orderId, $ids, $actorId, $key, $fingerprint, $reason, $note) {
            $order = $event->orders()->whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                return $this->result($orderId, null, 'skipped', 'Pesanan tidak ditemukan pada event ini.');
            }

            $replay = TicketCancellation::where('order_id', $orderId)->where('request_key', $key)->first();
            if ($replay) {
                return hash_equals($replay->payload_hash, $fingerprint)
                    ? $this->result($orderId, $order->kode_order, $replay->status, $replay->message)
                    : $this->result($orderId, $order->kode_order, 'skipped', 'ID permintaan telah digunakan dengan pilihan atau alasan berbeda.');
            }

            $tickets = $order->tickets()->whereIn('id', $ids)->with(self::TICKET_RELATIONS)->orderBy('id')->get();
            // Scan uses the same pass row lock. Read cancellation and check-in state
            // from these freshly locked rows, not from preview or eager-load snapshots.
            $passes = TicketPass::with('session')->whereIn('ticket_id', $tickets->modelKeys())
                ->orderBy('id')->lockForUpdate()->get()->groupBy('ticket_id');
            $targets = $tickets->map(fn (Ticket $ticket) => $this->describe($ticket, $passes->get($ticket->id, collect())) + [
                'pass_ids' => $passes->get($ticket->id, collect())->pluck('id')->all(),
            ])->all();

            $message = $tickets->count() !== count($ids) ? 'Ada tiket yang bukan milik pesanan ini.' : null;
            foreach ($tickets as $ticket) {
                $message ??= match ($this->status($order, $ticket, $passes->get($ticket->id, collect()))) {
                    'cancelled' => 'Setidaknya satu tiket sudah dibatalkan.',
                    'checked_in' => 'Setidaknya satu tiket sudah dipakai untuk check-in.',
                    'ineligible' => 'Setidaknya satu tiket tidak aktif atau tidak dapat dibatalkan.',
                    default => null,
                };
            }

            $status = $message === null ? 'cancelled' : 'skipped';
            $message ??= 'QR tiket yang dipilih berhasil dibatalkan.';
            $audit = TicketCancellation::create([
                'order_id' => $orderId,
                'actor_id' => $actorId,
                'request_key' => $key,
                'payload_hash' => $fingerprint,
                'public_reason' => $reason,
                'internal_note' => $note,
                'targets' => ['requested_ids' => $ids, 'tickets' => $targets],
                'status' => $status,
                'message' => $message,
            ]);

            if ($status === 'cancelled') {
                TicketPass::whereIn('id', $passes->flatten(1)->pluck('id'))->update(['cancelled_at' => now()]);
                TicketCancellationNotification::create([
                    'ticket_cancellation_id' => $audit->id,
                    'recipient' => $order->email,
                    'message_id' => (string) Str::uuid(),
                    'content' => [
                        'kode_order' => $order->kode_order,
                        'event' => $event->nama_event,
                        'reason' => $reason,
                        'tickets' => array_map(fn ($ticket) => ['nama_tiket' => $ticket['nama_tiket'], 'sesi' => $ticket['sesi']], $targets),
                    ],
                    'status' => 'pending',
                ]);
            }

            return $this->result($orderId, $order->kode_order, $status, $message);
        }, 3);
    }

    private function result(int $id, ?string $code, string $status, string $message): array
    {
        return ['order_id' => $id, 'kode_order' => $code, 'status' => $status, 'message' => $message];
    }

    private function describe(Ticket $ticket, Collection $passes): array
    {
        return [
            'id' => $ticket->id,
            'nama_tiket' => $ticket->ticketType?->nama_tiket,
            'sesi' => $passes->map(fn (TicketPass $pass) => $pass->session?->label ?? 'Tiket Masuk')->values()->all(),
        ];
    }

    private function status(Order $order, Ticket $ticket, Collection $passes): string
    {
        if ($passes->contains(fn (TicketPass $pass) => $pass->cancelled_at !== null)) {
            return 'cancelled';
        }
        if ($passes->contains(fn (TicketPass $pass) => $pass->status_kehadiran === TicketStatus::CHECKED_IN || $pass->checkin_at !== null)) {
            return 'checked_in';
        }

        // Eligibility depends on issuance and ownership, not name or price.
        $item = $ticket->orderItem;
        if (! $order->isPaid() || $passes->isEmpty() || ! $item
            || $item->order_id !== $order->id || $item->ticket_type_id !== $ticket->ticket_type_id
            || $ticket->ticketType?->event_id !== $order->event_id) {
            return 'ineligible';
        }

        return 'eligible';
    }
}
