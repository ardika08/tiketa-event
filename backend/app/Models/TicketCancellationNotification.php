<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketCancellationNotification extends Model
{
    protected $fillable = ['ticket_cancellation_id', 'recipient', 'content', 'message_id', 'status'];

    protected $hidden = ['recipient', 'content', 'message_id'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }

    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(TicketCancellation::class, 'ticket_cancellation_id');
    }
}
