<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Jobs\SendResendTicketEmail;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TicketController extends Controller
{
    /**
     * Lihat e-ticket berdasarkan kode order atau email pembeli.
     */
    public function show(Request $request, OrderService $orders)
    {
        $data = $request->validate([
            'kode_order' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
        ]);

        if (empty($data['kode_order']) && empty($data['email'])) {
            throw ValidationException::withMessages(['kode_order' => 'Isi kode order atau email.']);
        }

        $order = Order::query()
            ->where('status', 'lunas')
            ->when($data['kode_order'] ?? null, fn ($q, $v) => $q->where('kode_order', $v))
            ->when($data['email'] ?? null, fn ($q, $v) => $q->where('email', $v))
            ->with($orders->relations())
            ->latest('paid_at')
            ->first();

        if (! $order) {
            throw ValidationException::withMessages(['kode_order' => 'Tiket tidak ditemukan atau pesanan belum lunas.']);
        }

        return new OrderResource($order);
    }

    /**
     * Kirim ulang e-ticket ke email pembeli.
     */
    public function resend(Request $request, OrderService $orders)
    {
        $data = $request->validate([
            'kode_order' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
        ]);

        if (empty($data['kode_order']) && empty($data['email'])) {
            throw ValidationException::withMessages(['kode_order' => 'Isi kode order atau email.']);
        }

        $order = Order::query()
            ->where('status', 'lunas')
            ->when($data['kode_order'] ?? null, fn ($q, $v) => $q->where('kode_order', $v))
            ->when($data['email'] ?? null, fn ($q, $v) => $q->where('email', $v))
            ->latest('paid_at')
            ->first();

        if (! $order) {
            throw ValidationException::withMessages(['kode_order' => 'Pesanan tidak ditemukan.']);
        }

        SendResendTicketEmail::dispatch($order->id);

        return response()->json(['message' => 'E-ticket akan dikirim ulang ke '.$order->email.'.']);
    }
}
