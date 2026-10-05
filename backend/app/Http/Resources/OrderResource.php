<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->whenLoaded('payments', fn () => $this->payments->sortByDesc('id')->first());
        $paymentUrl = $this->whenLoaded('payments', function () {
            $latest = $this->payments->sortByDesc('id')->first();

            return $latest ? (data_get($latest->payload, 'payment_url') ?? data_get($latest->payload, 'link')) : null;
        });
        $paymentProvider = $this->whenLoaded('payments', function () {
            $latest = $this->payments->sortByDesc('id')->first();

            return $latest ? (data_get($latest->payload, 'mode') === 'live' ? 'mayar' : 'fake') : null;
        });

        return [
            'id' => $this->id,
            'kode_order' => $this->kode_order,
            'status' => $this->status?->value,
            'buyer' => [
                'nama' => $this->nama_pembeli,
                'email' => $this->email,
                'whatsapp' => $this->whatsapp,
                'instagram' => $this->instagram,
                'tiktok' => $this->tiktok,
                'threads' => $this->threads,
                'form_data' => $this->form_data,
            ],
            'subtotal' => (float) $this->subtotal,
            'diskon' => (float) $this->diskon,
            'total_harga' => (float) $this->total_harga,
            'biaya_layanan' => (float) $this->biaya_layanan,
            'voucher' => $this->whenLoaded('voucher', fn () => $this->voucher?->kode),
            'batas_bayar' => $this->batas_bayar?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_status' => $payment?->status?->value,
            'payment_provider' => $paymentProvider,
            'payment_url' => $paymentUrl,
            'event' => new EventResource($this->whenLoaded('event')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'tickets' => TicketResource::collection($this->whenLoaded('tickets')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
