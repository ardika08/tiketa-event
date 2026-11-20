<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormField extends Model
{
    use HasFactory;

    protected $fillable = [
        'organizer_id',
        'event_id',
        'ticket_type_id',
        'ticket_type_ids',
        'label',
        'key',
        'tipe',
        'wajib',
        'urutan',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'wajib' => 'boolean',
            'ticket_type_ids' => 'array',
        ];
    }

    /** @return list<int> */
    public function targetTicketIds(): array
    {
        return $this->ticket_type_ids ?? ($this->ticket_type_id === null ? [] : [(int) $this->ticket_type_id]);
    }

    public function appliesToTicket(int $ticketId): bool
    {
        return ($this->ticket_type_ids === null && $this->ticket_type_id === null)
            || in_array($ticketId, $this->targetTicketIds(), true);
    }

    public function scopeActiveForEvent(Builder $query, Event $event): Builder
    {
        return $query->where('status', 'aktif')
            ->where(fn (Builder $fields) => $fields->whereNull('ticket_type_ids')->orWhereJsonLength('ticket_type_ids', '>', 0))
            ->where(function (Builder $fields) use ($event) {
                $fields->where('event_id', $event->id)
                    ->orWhere(function (Builder $organizerFields) use ($event) {
                        $organizerFields->where('organizer_id', $event->organizer_id)
                            ->whereNull('event_id')
                            ->whereNull('ticket_type_id')
                            ->whereNull('ticket_type_ids');
                    });
            })
            ->orderBy('urutan')->orderBy('id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }
}
