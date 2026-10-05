<?php

namespace App\Models;

use App\Enums\GateStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gate extends Model
{
    use HasFactory;

    protected $fillable = [
        'organizer_id',
        'event_id',
        'nama_gate',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => GateStatus::class,
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function staffs(): HasMany
    {
        return $this->hasMany(Staff::class);
    }
}
