<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_type_id' => $this->ticket_type_id,
            'nama_tiket' => $this->whenLoaded('ticketType', fn () => $this->ticketType?->nama_tiket),
            'jumlah' => $this->jumlah,
            'harga_satuan' => (float) $this->harga_satuan,
            'subtotal' => (float) $this->subtotal,
            'session_ids' => $this->whenLoaded('ticketType', fn () => $this->ticketType->sessions->pluck('id')->values()->all()),
        ];
    }
}
