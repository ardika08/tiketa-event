<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendOrderPaidEmail;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketPass;
use App\Models\TicketType;
use App\Models\Voucher;
use App\Support\Code;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private VoucherService $vouchers,
        private MayarService $mayar,
    ) {
    }

    /**
     * Buat order baru: validasi kuota, reservasi sisa kuota, hitung total.
     * Tiket + QR baru dibuat setelah pembayaran lunas (lihat markPaid).
     *
     * @param  array<int, array{ticket_type_id:int, jumlah:int}>  $items
     * @param  array<string, mixed>  $buyer
     */
    public function create(Event $event, array $buyer, array $items, ?string $voucherCode = null): Order
    {
        if (empty($items)) {
            throw ValidationException::withMessages(['items' => 'Pilih minimal satu tiket.']);
        }

        return DB::transaction(function () use ($event, $buyer, $items, $voucherCode) {
            $jumlahTiket = 0;
            $subtotal = 0;
            $resolved = [];

            foreach ($items as $row) {
                /** @var TicketType|null $ticketType */
                $ticketType = TicketType::where('event_id', $event->id)
                    ->lockForUpdate()
                    ->find($row['ticket_type_id']);

                if (! $ticketType) {
                    throw ValidationException::withMessages(['items' => 'Jenis tiket tidak ditemukan pada event ini.']);
                }

                $jumlah = (int) $row['jumlah'];

                if ($jumlah < 1) {
                    throw ValidationException::withMessages(['items' => "Jumlah tiket {$ticketType->nama_tiket} tidak valid."]);
                }
                if ($jumlah > $ticketType->max_per_order) {
                    throw ValidationException::withMessages(['items' => "Maksimal {$ticketType->max_per_order} tiket untuk {$ticketType->nama_tiket} per pesanan."]);
                }
                if (! $ticketType->hasStock($jumlah)) {
                    throw ValidationException::withMessages(['items' => "Sisa kuota {$ticketType->nama_tiket} tidak mencukupi."]);
                }

                $subtotal += (float) $ticketType->harga * $jumlah;
                $jumlahTiket += $jumlah;
                $resolved[] = [$ticketType, $jumlah];
            }

            $voucher = null;
            $diskon = 0.0;
            if ($voucherCode) {
                $voucher = $this->vouchers->resolve($voucherCode, $event);
                $diskon = $this->vouchers->hitungDiskon($voucher, $subtotal);
            }

            $order = Order::create([
                'event_id' => $event->id,
                'nama_pembeli' => $buyer['nama'],
                'email' => $buyer['email'],
                'whatsapp' => $buyer['whatsapp'],
                'instagram' => $buyer['instagram'] ?? null,
                'tiktok' => $buyer['tiktok'] ?? null,
                'threads' => $buyer['threads'] ?? null,
                'form_data' => $buyer['form_data'] ?? null,
                'voucher_id' => $voucher?->id,
                'subtotal' => $subtotal,
                'diskon' => $diskon,
                'total_harga' => $subtotal - $diskon,
                'biaya_layanan' => (float) config('nontix.service_fee') * $jumlahTiket,
                'status' => OrderStatus::PENDING,
                'batas_bayar' => now()->addMinutes((int) config('nontix.order_expiry_minutes')),
            ]);

            foreach ($resolved as [$ticketType, $jumlah]) {
                $order->items()->create([
                    'ticket_type_id' => $ticketType->id,
                    'jumlah' => $jumlah,
                    'harga_satuan' => $ticketType->harga,
                    'subtotal' => (float) $ticketType->harga * $jumlah,
                ]);

                // Reservasi kuota selama order belum dibatalkan/dibayar.
                $ticketType->decrement('sisa_kuota', $jumlah);
            }

            if ($voucher) {
                $voucher->increment('terpakai');
            }

            return $order->fresh($this->relations());
        });
    }

    /**
     * Tandai order lunas, buat tiket + QR, lalu kirim email invoice + e-ticket.
     */
    public function markPaid(Order $order, ?array $payload = null, ?string $metode = null): Order
    {
        if ($order->isPaid()) {
            return $order->fresh($this->relations());
        }

        if ($order->status !== OrderStatus::PENDING) {
            Log::warning('[Order] Pembayaran diterima untuk order non-pending', [
                'order' => $order->kode_order,
                'status' => $order->status->value,
            ]);

            return $order->fresh($this->relations());
        }

        DB::transaction(function () use ($order, $payload, $metode) {
            $order->update([
                'status' => OrderStatus::PAID,
                'paid_at' => now(),
            ]);

            $payment = $order->payments()->latest()->first();
            if ($payment) {
                $payment->update([
                    'status' => PaymentStatus::SUCCESS,
                    'paid_at' => now(),
                    'metode' => $metode ?? $payment->metode,
                    'payload' => $payload ?? $payment->payload,
                ]);
            }

            $this->generateTickets($order);
        });

        SendOrderPaidEmail::dispatch($order->id);

        return $order->fresh($this->relations());
    }

    /**
     * Buat tiket + QR per sesi untuk order yang sudah lunas.
     * Idempoten: dilewati bila tiket order sudah pernah dibuat.
     */
    public function generateTickets(Order $order): void
    {
        $order->loadMissing(['items.ticketType.sessions', 'tickets']);

        if ($order->tickets()->exists()) {
            return;
        }

        foreach ($order->items as $item) {
            $ticketType = $item->ticketType;

            if (! $ticketType) {
                continue;
            }

            $sessions = $ticketType->sessions()->orderBy('urutan')->get();
            $sessionIds = $sessions->isEmpty() ? [null] : $sessions->pluck('id')->all();

            for ($i = 0; $i < $item->jumlah; $i++) {
                $ticket = Ticket::create([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'ticket_type_id' => $ticketType->id,
                    'kode_tiket' => $this->uniqueTicketCode(),
                    'nama_pemegang' => $order->nama_pembeli,
                ]);

                foreach ($sessionIds as $sessionId) {
                    TicketPass::create([
                        'ticket_id' => $ticket->id,
                        'event_session_id' => $sessionId,
                        'kode_qr' => $this->uniqueQrCode(),
                    ]);
                }
            }
        }
    }

    /**
     * Batalkan order (manual/kadaluarsa) dan kembalikan kuota + voucher.
     */
    public function cancel(Order $order, OrderStatus $status = OrderStatus::CANCELLED): Order
    {
        if ($order->status !== OrderStatus::PENDING) {
            return $order->fresh($this->relations());
        }

        DB::transaction(function () use ($order, $status) {
            foreach ($order->items as $item) {
                $item->ticketType()->increment('sisa_kuota', $item->jumlah);
            }

            if ($order->voucher_id) {
                Voucher::where('id', $order->voucher_id)
                    ->where('terpakai', '>', 0)
                    ->decrement('terpakai');
            }

            $order->update(['status' => $status]);
        });

        // Batalkan invoice Mayar (hanya berjalan pada mode live).
        $this->mayar->voidInvoice($order);

        return $order->fresh($this->relations());
    }

    /**
     * Batalkan semua order pending yang melewati batas bayar.
     */
    public function expireOverdue(): int
    {
        $count = 0;

        Order::expired()->with('items')->chunkById(100, function ($orders) use (&$count) {
            foreach ($orders as $order) {
                $this->cancel($order, OrderStatus::EXPIRED);
                $count++;
            }
        });

        return $count;
    }

    /**
     * @return array<int, string>
     */
    public function relations(): array
    {
        return [
            'event.sessions',
            'items.ticketType.sessions',
            'tickets.ticketType',
            'tickets.passes.session',
            'voucher',
            'payments',
        ];
    }

    private function uniqueTicketCode(): string
    {
        do {
            $code = Code::ticket();
        } while (Ticket::where('kode_tiket', $code)->exists());

        return $code;
    }

    private function uniqueQrCode(): string
    {
        do {
            $code = Code::qr();
        } while (TicketPass::where('kode_qr', $code)->exists());

        return $code;
    }
}
