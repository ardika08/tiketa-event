<?php

namespace App\Http\Controllers\Api\Public;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(private PaymentManager $manager)
    {
    }

    /**
     * Daftar gateway yang tersedia untuk dipilih pembeli.
     */
    public function gateways()
    {
        return response()->json(['data' => $this->manager->options()]);
    }

    /**
     * Webhook dari payment gateway.
     * Satu URL menangani semua provider; provider dikenali dari payload/token.
     *
     * @see https://docs.mayar.id/integration/webhook
     * @see https://docs.xendit.co/apidocs/payment-webhook-notification
     */
    public function callback(Request $request, OrderService $orders)
    {
        $payload = $request->all();
        $token = $request->header('x-callback-token')
            ?? $request->header('X-Callback-Token')
            ?? $request->query('token')
            ?? $request->input('token');

        foreach ($this->manager->enabledGateways() as $gateway) {
            $order = $gateway->resolveOrder($payload);

            if (! $order) {
                continue;
            }

            if (! $gateway->verifyWebhook($payload, $token)) {
                Log::warning('[Payment] Token webhook tidak valid', ['gateway' => $gateway->name(), 'order' => $order->kode_order]);

                return response()->json(['message' => 'Token callback tidak valid.'], 401);
            }

            // Verifikasi ganda ke API gateway (sumber kebenaran).
            $paid = $gateway->isPaidPayload($payload) && $gateway->confirmPaid($order);

            if ($paid) {
                $orders->markPaid($order, $payload, $gateway->name());
                Log::info('[Payment] Webhook menandai order lunas', ['gateway' => $gateway->name(), 'order' => $order->kode_order]);
            } else {
                Log::info('[Payment] Webhook diabaikan', ['gateway' => $gateway->name(), 'order' => $order->kode_order]);
            }

            return response()->json(['message' => 'Callback diterima.']);
        }

        return response()->json(['message' => 'Order tidak ditemukan untuk payload ini.'], 404);
    }

    /**
     * Simulasi pembayaran sukses (mode fake, untuk demo).
     */
    public function fake(string $kodeOrder, OrderService $orders)
    {
        $order = Order::where('kode_order', $kodeOrder)->firstOrFail();

        if ($this->manager->enabledGateways() && $this->allLive()) {
            return response()->json(['message' => 'Simulasi tidak tersedia pada mode live.'], 403);
        }

        $orders->markPaid($order, ['mode' => 'fake', 'status' => 'paid'], 'demo');

        return response()->json([
            'message' => 'Pembayaran disimulasikan berhasil.',
            'order' => new OrderResource($order->fresh($orders->relations())),
        ]);
    }

    /**
     * Cek status langsung ke gateway lalu kembalikan order terbaru.
     */
    public function sync(string $kodeOrder, OrderService $orders)
    {
        $order = Order::where('kode_order', $kodeOrder)->firstOrFail();

        if ($order->isPending() && ($gateway = $this->manager->forOrder($order))) {
            if ($gateway->confirmPaid($order)) {
                $orders->markPaid($order, ['mode' => 'sync', 'status' => 'paid'], $gateway->name());
            }
        }

        return new OrderResource($order->fresh($orders->relations()));
    }

    public function status(string $kodeOrder, OrderService $orders)
    {
        $order = Order::where('kode_order', $kodeOrder)
            ->with($orders->relations())
            ->firstOrFail();

        return new OrderResource($order);
    }

    private function allLive(): bool
    {
        foreach ($this->manager->enabledGateways() as $gateway) {
            if (method_exists($gateway, 'isLive') && $gateway->isLive()) {
                return true;
            }
            if ($gateway->name() === 'xendit' && $gateway->isEnabled()) {
                return true;
            }
        }

        return false;
    }
}