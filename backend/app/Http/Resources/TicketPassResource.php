<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketPassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode_qr' => $this->kode_qr,
            'event_session_id' => $this->event_session_id,
            'session_label' => $this->whenLoaded('session', fn () => $this->session?->label),
            'session_name' => $this->whenLoaded('session', fn () => $this->session?->nama_session),
            'tanggal_mulai' => $this->whenLoaded('session', fn () => $this->session?->tanggal_mulai?->toIso8601String()),
            'lokasi' => $this->whenLoaded('session', fn () => $this->session?->lokasi),
            'status_kehadiran' => $this->status_kehadiran?->value,
            'checkin_at' => $this->checkin_at?->toIso8601String(),
        ];
    }
}
