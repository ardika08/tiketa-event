<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketPass extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'event_session_id',
        'kode_qr',
        'status_kehadiran',
        'checkin_at',
    ];

    protected function casts(): array
    {
        return [
            'status_kehadiran' => TicketStatus::class,
            'checkin_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(EventSession::class, 'event_session_id');
    }
}
