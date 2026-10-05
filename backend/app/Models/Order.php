<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'kode_order',
        'nama_pembeli',
        'email',
        'whatsapp',
        'instagram',
        'tiktok',
        'threads',
        'form_data',
        'voucher_id',
        'subtotal',
        'diskon',
        'total_harga',
        'biaya_layanan',
        'gateway',
        'status',
        'batas_bayar',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'form_data' => 'array',
            'subtotal' => 'decimal:2',
            'diskon' => 'decimal:2',
            'total_harga' => 'decimal:2',
            'biaya_layanan' => 'decimal:2',
            'status' => OrderStatus::class,
            'batas_bayar' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->kode_order)) {
                $order->kode_order = 'NTX-'.strtoupper(Str::random(6));
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === OrderStatus::PAID;
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::PENDING)
            ->whereNotNull('batas_bayar')
            ->where('batas_bayar', '<', now());
    }
}
