<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\VoucherService;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function validate(Request $request, VoucherService $service)
    {
        $data = $request->validate([
            'kode' => ['required', 'string'],
            'event_id' => ['required', 'exists:events,id'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
        ]);

        $event = Event::findOrFail($data['event_id']);
        $voucher = $service->resolve($data['kode'], $event);
        $subtotal = (float) ($data['subtotal'] ?? 0);

        return response()->json([
            'kode' => $voucher->kode,
            'tipe_diskon' => $voucher->tipe_diskon->value,
            'nilai' => (float) $voucher->nilai,
            'diskon' => $subtotal > 0 ? $service->hitungDiskon($voucher, $subtotal) : null,
        ]);
    }
}
