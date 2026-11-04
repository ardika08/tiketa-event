<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketType extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'nama_tiket',
        'harga',
        'kuota',
        'sisa_kuota',
        'max_per_order',
        'jam_masuk_mulai',
        'jam_masuk_selesai',
        'gambar_url',
        'is_bundle',
        'status',
        // Tersembunyi dari halaman publik, tapi masih bisa dijual manual.
        'sembunyi',
    ];

    /**
     * Tiket baru tampil di publik sampai partner menyembunyikannya.
     *
     * Default dideklarasikan di model, bukan hanya di kolom DB, supaya
     * instance yang baru dibuat lewat create() tapi belum di-refresh sudah
     * punya nilai false — tanpa ini `sembunyi` terbaca null.
     */
    protected $attributes = [
        'sembunyi' => false,
    ];

    protected function casts(): array
    {
        return [
            'harga' => 'decimal:2',
            'is_bundle' => 'boolean',
            // boolean() eksplisit supaya nilai dari DB tetap true/false, bukan
            // 1/0 atau string "0" yang ternyata truthy di JavaScript.
            'sembunyi' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function sessions(): BelongsToMany
    {
        return $this->belongsToMany(EventSession::class, 'event_session_ticket_type');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function hasStock(int $qty): bool
    {
        return $this->sisa_kuota >= $qty;
    }
}
