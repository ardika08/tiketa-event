<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketCancellation extends Model
{
    protected $fillable = ['order_id', 'actor_id', 'request_key', 'payload_hash', 'public_reason', 'internal_note', 'targets', 'status', 'message'];

    protected $hidden = ['internal_note', 'request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['targets' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
