<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Event;
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