<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'nama_session' => $this->nama_session,
            'tanggal_mulai' => $this->tanggal_mulai?->toIso8601String(),
            'tanggal_selesai' => $this->tanggal_selesai?->toIso8601String(),
            'lokasi' => $this->lokasi,
            'kapasitas' => $this->kapasitas,
            'urutan' => $this->urutan,
            'status' => $this->status?->value,
        ];
    }
}
