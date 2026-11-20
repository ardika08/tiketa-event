<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Event;
use App\Models\FormField;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(Request $request, OrderService $orders, PaymentManager $payments)
    {
        $data = $request->validate([
            'event_id' => ['required', 'exists:events,id'],
            'nama' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email'],
            'whatsapp' => ['required', 'string', 'max:30'],
            'instagram' => ['nullable', 'string', 'max:100'],
            'tiktok' => ['nullable', 'string', 'max:100'],
            'threads' => ['nullable', 'string', 'max:100'],
            'form_data' => ['nullable', 'array'],
            'voucher_code' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ticket_type_id' => ['required', 'integer'],
            'items.*.jumlah' => ['required', 'integer', 'min:1'],
        ]);

        $event = Event::active()->findOrFail($data['event_id']);

        $orderedTicketIds = collect($data['items'])
            ->pluck('ticket_type_id')
            ->map(fn ($id) => (int) $id)
            ->unique();
        $orderAnswers = $data['form_data']['order'] ?? [];
        $ticketAnswers = $data['form_data']['tickets'] ?? [];
        $fieldErrors = [];

        foreach (FormField::activeForEvent($event)->where('wajib', true)->get() as $field) {
            if ($field->targetTicketIds() === []) {
                $key = strtolower($field->key ?: $field->label);
                $value = $this->fieldAnswer($field, $orderAnswers);
                if (in_array($key, ['instagram', 'tiktok', 'threads'], true)) {
                    $value = $value ?: ($data[$key] ?? null);
                }
                if (! $this->hasAnswer($value)) {
                    $fieldErrors["form_data.{$field->id}"] = "{$field->label} wajib diisi.";
                }

                continue;
            }

            foreach ($orderedTicketIds as $ticketId) {
                if ($field->appliesToTicket($ticketId)
                    && ! $this->hasAnswer($this->fieldAnswer($field, $ticketAnswers[$ticketId] ?? []))) {
                    $errorKey = $field->ticket_type_ids === null
                        ? "form_data.{$field->id}"
                        : "form_data.tickets.{$ticketId}.{$field->id}";
                    $fieldErrors[$errorKey] = "{$field->label} wajib diisi.";
                }
            }
        }

        if ($fieldErrors) {
            throw ValidationException::withMessages($fieldErrors);
        }

        $order = $orders->create(
            $event,
            [
                'nama' => $data['nama'],
                'email' => $data['email'],
                'whatsapp' => $data['whatsapp'],
                'instagram' => $data['instagram'] ?? null,
                'tiktok' => $data['tiktok'] ?? null,
                'threads' => $data['threads'] ?? null,
                'form_data' => $data['form_data'] ?? null,
            ],
            $data['items'],
            $data['voucher_code'] ?? null,
        );

        // Total Rp 0 (tiket gratis atau voucher menutup seluruh harga) tidak
        // boleh dikirim ke gateway: banyak provider menolak invoice nominal 0.
        // Buat jejak pembayaran gratis, lalu markPaid() menghasilkan e-ticket + QR
        // dengan jalur yang sama seperti callback gateway — tanpa celah bypass.
        if ((float) $order->total_harga <= 0) {
            $order->payments()->create([
                'jumlah' => 0,
                'status' => PaymentStatus::PENDING,
                'provider' => 'gratis',
                'metode' => 'gratis',
                'payload' => ['sumber' => 'checkout_gratis'],
            ]);
            $order = $orders->markPaid($order, ['sumber' => 'checkout_gratis'], 'gratis');

            return (new OrderResource($order))->response()->setStatusCode(201);
        }

        $created = $payments->createFor($order);

        if (! $created) {
            $orders->cancel($order);

            throw ValidationException::withMessages([
                'gateway' => 'Tidak ada metode pembayaran yang tersedia saat ini. Coba lagi nanti.',
            ]);
        }

        return (new OrderResource($order->fresh($orders->relations())))
            ->response()
            ->setStatusCode(201);
    }

    private function fieldAnswer(FormField $field, mixed $answers): mixed
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

    private function hasAnswer(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    public function show(string $kodeOrder, OrderService $orders)
    {
        $order = Order::where('kode_order', $kodeOrder)
            ->with($orders->relations())
            ->firstOrFail();

        return new OrderResource($order);
    }
}
