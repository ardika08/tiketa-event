<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VoucherController extends Controller
{
    public function validate(Request $request, VoucherService $service)
    {
        $data = $request->validate([
            'kode' => ['required', 'string'],
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'items' => ['sometimes', 'required', 'array', 'list', 'min:1'],
            'items.*.ticket_type_id' => ['required', 'integer'],
            'items.*.jumlah' => ['required', 'integer', 'min:1'],
        ]);

        $event = Event::findOrFail($data['event_id']);
        $voucher = $service->resolve($data['kode'], $event);

        if (isset($data['items'])) {
            $tickets = $event->ticketTypes()->where('status', 'aktif')
                ->whereKey(array_column($data['items'], 'ticket_type_id'))->get()->keyBy('id');
            $items = [];
            foreach ($data['items'] as $item) {
                $ticket = $tickets->get((int) $item['ticket_type_id']);
                if (! $ticket) {
                    throw ValidationException::withMessages(['items' => 'Jenis tiket tidak tersedia pada event ini.']);
                }

                $items[] = [$ticket, (int) $item['jumlah']];
            }
            $diskon = $service->discountForItems($voucher, $items);
        } else {
            $diskon = $service->discountForLegacySubtotal($voucher, (float) ($data['subtotal'] ?? 0));
        }

        return response()->json([
            'kode' => $voucher->kode,
            'tipe_diskon' => $voucher->tipe_diskon->value,
            'nilai' => (float) $voucher->nilai,
            'min_pembelian' => (float) $voucher->min_pembelian,
            'diskon' => $diskon,
        ]);
    }
}
