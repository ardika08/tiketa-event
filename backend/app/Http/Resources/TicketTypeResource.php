<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'nama_tiket' => $this->nama_tiket,
            'harga' => (float) $this->harga,
            'kuota' => $this->kuota,
            'sisa_kuota' => $this->sisa_kuota,
            'max_per_order' => $this->max_per_order,
            'terjual_lunas' => (int) ($this->terjual_lunas ?? 0),
            'gambar_url' => $this->gambar_url,
            'is_bundle' => (bool) $this->is_bundle,
            'status' => $this->status,
            'session_ids' => $this->whenLoaded('sessions', fn () => $this->sessions->pluck('id')->values()->all()),
            'sessions' => EventSessionResource::collection($this->whenLoaded('sessions')),
        ];
    }
}
