<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'nama_denah',
        'layout_url',
        'gambar_url',
        'rows',
    ];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
