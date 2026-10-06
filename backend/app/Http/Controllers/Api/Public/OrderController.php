<?php

namespace App\Http\Controllers\Api\Public;

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

        // Validasi server-side field form custom: field berstatus wajib tidak
        // boleh dikosongkan (juga lewat API mentah). Field khusus jenis tiket
        // hanya divalidasi bila tiket tersebut benar-benar dipesan.
        $fields = FormField::query()
            ->where('status', 'aktif')
            ->where(function ($q) use ($event) {
                $q->where('event_id', $event->id)
                    ->orWhere(function ($qq) use ($event) {
                        $qq->whereNull('event_id')->where('organizer_id', $event->organizer_id);
                    });
            })
            ->get();

        $orderedTicketIds = collect($data['items'])
            ->pluck('ticket_type_id')
            ->map(fn ($id) => (int) $id);
        $orderAnswers = $data['form_data']['order'] ?? [];
        $ticketAnswers = $data['form_data']['tickets'] ?? [];
        $fieldErrors = [];

        foreach ($fields as $field) {
            if ($field->ticket_type_id && ! $orderedTicketIds->contains((int) $field->ticket_type_id)) {
                continue;
            }

            $answers = $field->ticket_type_id
                ? ($ticketAnswers[(string) $field->ticket_type_id] ?? [])
                : $orderAnswers;

            // Cari nilai jawaban berdasarkan label asli, label lowercase, atau key
            $key = strtolower($field->key ?: $field->label);
            $value = $answers[$field->label]
                ?? $answers[$field->key ?? '']
                ?? ($answers[$key] ?? null)
                ?? ($answers[strtolower($field->label)] ?? null);

            // Jika field order-level adalah sosial standar, fallback ke kolom root order (data.instagram, dll)
            if (! $field->ticket_type_id && in_array($key, ['instagram', 'tiktok', 'threads'], true)) {
                $value = $value ?: ($data[$key] ?? null);
            }

            if ($field->wajib && ($value === null || trim((string) $value) === '')) {
                $fieldErrors["form_data.{$field->id}"] = "{$field->label} wajib diisi.";
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

    public function show(string $kodeOrder, OrderService $orders)
    {
        $order = Order::where('kode_order', $kodeOrder)
            ->with($orders->relations())
            ->firstOrFail();

        return new OrderResource($order);
    }
}