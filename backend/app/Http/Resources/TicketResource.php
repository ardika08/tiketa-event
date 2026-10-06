<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $passes = $this->whenLoaded('passes');
        $sessionIds = $this->whenLoaded('passes', fn () => $this->passes->pluck('event_session_id')->filter()->values());
        $labels = $this->whenLoaded('passes', fn () => $this->passes
            ->map(fn ($pass) => $pass->session?->label)
            ->filter()
            ->values());

        return [
            'id' => $this->id,
            'kode_tiket' => $this->kode_tiket,
            'nama_pemegang' => $this->nama_pemegang,
            'ticket_type_id' => $this->ticket_type_id,
            'nama_tiket' => $this->whenLoaded('ticketType', fn () => $this->ticketType?->nama_tiket),
            'jam_masuk_mulai' => $this->whenLoaded('ticketType', fn () => $this->ticketType?->jam_masuk_mulai ? substr($this->ticketType->jam_masuk_mulai, 0, 5) : null),
            'jam_masuk_selesai' => $this->whenLoaded('ticketType', fn () => $this->ticketType?->jam_masuk_selesai ? substr($this->ticketType->jam_masuk_selesai, 0, 5) : null),
            'gambar_url' => $this->whenLoaded('ticketType', fn () => $this->ticketType?->gambar_url),
            'status_kehadiran' => $this->status_kehadiran?->value,
            'checkin_at' => $this->checkin_at?->toIso8601String(),
            'is_bundle' => $this->whenLoaded('passes', fn () => $this->passes->whereNotNull('event_session_id')->count() > 1),
            'session_ids' => $sessionIds,
            'session_labels' => $labels,
            'passes' => TicketPassResource::collection($passes),
        ];
    }
}
