<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\MayarService;
use App\Services\OrderService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Webhook dari Mayar (event: payment.received).
     *
     * @see https://docs.mayar.id/integration/webhook
     */
    public function callback(Request $request, MayarService $mayar, OrderService $orders)
    {
        $payload = $request->all();
        $token = $request->header('X-Callback-Token')
            ?? $request->query('token')
            ?? $request->input('token');

        if (! $mayar->verifyCallback($payload, $token)) {
            return response()->json(['message' => 'Token callback tidak valid.'], 401);
        }

        $order = $this->resolveOrder($payload, $mayar);
        if (! $order) {
            return response()->json(['message' => 'Order tidak ditemukan untuk payload ini.'], 404);
        }

        if ($mayar->isPaidPayload($payload)) {
            $orders->markPaid($order, $payload, 'mayar');
        }

        return response()->json(['message' => 'Callback diterima.']);
    }

    /**
     * Simulasi pembayaran sukses (mode fake, untuk demo).
     */
    public function fake(string $kodeOrder, MayarService $mayar, OrderService $orders)
    {
        $order = Order::where('kode_order', $kodeOrder)->firstOrFail();

        if ($mayar->isLive()) {
            return response()->json(['message' => 'Simulasi tidak tersedia pada mode live.'], 403);
        }

        $orders->markPaid($order, ['mode' => 'fake', 'status' => 'paid'], 'demo');

        return response()->json([
            'message' => 'Pembayaran disimulasikan berhasil.',
            'order' => new OrderResource($order->fresh($orders->relations())),
        ]);
    }

    /**
     * Cek status langsung ke Mayar lalu kembalikan order terbaru.
     */
    public function sync(string $kodeOrder, MayarService $mayar, OrderService $orders)
    {
        $order = Order::where('kode_order', $kodeOrder)->firstOrFail();
        $mayar->syncPayment($order, $orders);

        return new OrderResource($order->fresh($orders->relations()));
    }

    public function status(string $kodeOrder, OrderService $orders)
    {
        $order = Order::where('kode_order', $kodeOrder)
            ->with($orders->relations())
            ->firstOrFail();

        return new OrderResource($order);
    }

    private function resolveOrder(array $payload, MayarService $mayar): ?Order
    {
        $kode = $mayar->extractOrderCode($payload);
        if ($kode && ($order = Order::where('kode_order', $kode)->first())) {
            return $order;
        }

        $invoiceId = $mayar->extractInvoiceId($payload);
        if ($invoiceId) {
            $payment = Payment::where('mayar_invoice_id', $invoiceId)->latest()->first();
            if ($payment) {
                return $payment->order;
            }
        }

        // Fallback: cocokkan berdasarkan email pembeli + nominal.
        $email = data_get($payload, 'data.customerEmail') ?? data_get($payload, 'customerEmail');
        $amount = data_get($payload, 'data.amount') ?? data_get($payload, 'amount');

        if ($email) {
            return Order::where('email', $email)
                ->where('status', 'pending')
                ->when($amount, fn ($q, $v) => $q->where('total_harga', $v))
                ->latest()
                ->first();
        }

        return null;
    }
}
