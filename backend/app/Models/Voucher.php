<?php

namespace App\Models;

use App\Enums\VoucherStatus;
use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'organizer_id',
        'event_id',
        'kode',
        'tipe_diskon',
        'nilai',
        'kuota',
        'terpakai',
        'berlaku_mulai',
        'berlaku_sampai',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tipe_diskon' => VoucherType::class,
            'status' => VoucherStatus::class,
            'nilai' => 'decimal:2',
            'berlaku_mulai' => 'date',
            'berlaku_sampai' => 'date',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function isUsable(Event|int|null $event = null): bool
    {
        if ($this->status !== VoucherStatus::ACTIVE) {
            return false;
        }
        if ($this->kuota > 0 && $this->terpakai >= $this->kuota) {
            return false;
        }

        $eventId = $event instanceof Event ? $event->id : $event;
        $eventModel = $event instanceof Event ? $event : ($eventId ? Event::find($eventId) : null);

        if ($this->event_id && $eventId && $this->event_id !== $eventId) {
            return false;
        }

        // Voucher tanpa event_id (berlaku luas) tetap dibatasi untuk organizer pemiliknya.
        if (! $this->event_id && $eventModel && (int) $this->organizer_id !== (int) $eventModel->organizer_id) {
            return false;
        }

        $today = now()->startOfDay();
        if ($this->berlaku_mulai && $today->lt($this->berlaku_mulai)) {
            return false;
        }
        if ($this->berlaku_sampai && $today->gt($this->berlaku_sampai)) {
            return false;
        }

        return true;
    }

    public function hitungDiskon(float $subtotal): float
    {
        $diskon = $this->tipe_diskon === VoucherType::PERCENT
            ? round($subtotal * ((float) $this->nilai) / 100)
            : (float) $this->nilai;

        return (float) min($diskon, $subtotal);
    }
}
