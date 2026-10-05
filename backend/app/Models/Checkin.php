<?php

namespace App\Models;

use App\Enums\CheckinStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Checkin extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_pass_id',
        'event_id',
        'event_session_id',
        'gate_id',
        'staff_id',
        'kode_qr',
        'nama_pemegang',
        'status',
        'catatan',
        'checked_in_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CheckinStatus::class,
            'checked_in_at' => 'datetime',
        ];
    }

    public function ticketPass(): BelongsTo
    {
        return $this->belongsTo(TicketPass::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(EventSession::class, 'event_session_id');
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
