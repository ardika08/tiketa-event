<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'nama_event' => $this->nama_event,
            'kategori' => $this->kategori,
            'deskripsi' => $this->deskripsi,
            'hero_image_url' => $this->hero_image_url,
            'tanggal_mulai' => $this->tanggal_mulai?->toIso8601String(),
            'tanggal_selesai' => $this->tanggal_selesai?->toIso8601String(),
            'lokasi' => $this->lokasi,
            'syarat_ketentuan' => $this->syarat_ketentuan,
            'sembunyikan_sisa_kuota' => (bool) $this->sembunyikan_sisa_kuota,
            'status' => $this->status?->value,
            'organizer' => $this->whenLoaded('organizer', fn () => [
                'id' => $this->organizer->id,
                'nama_penyelenggara' => $this->organizer->nama_penyelenggara,
                'logo_url' => $this->organizer->logo_url,
            ]),
            'sessions' => EventSessionResource::collection($this->whenLoaded('sessions')),
            'tiket' => TicketTypeResource::collection($this->whenLoaded('ticketTypes')),
            'total_sisa_kuota' => $this->whenLoaded('ticketTypes', fn () => (int) $this->ticketTypes->sum('sisa_kuota')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
