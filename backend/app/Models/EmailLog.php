<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    public const JENIS_INVOICE = 'invoice';
    public const JENIS_RESEND = 'resend';
    public const JENIS_MANUAL = 'manual_alternative';
    /** E-ticket dari penjualan manual partner (OTS/tunai/transfer). */
    public const JENIS_MANUAL_ORDER = 'manual_order';

    public const STATUS_PROSES = 'proses';
    public const STATUS_TERKIRIM = 'terkirim';
    public const STATUS_GAGAL = 'gagal';

    protected $fillable = [
        'order_id',
        'event_id',
        'jenis',
        'tujuan',
        'email_pesanan',
        'subject',
        'message_id',
        'status',
        'error',
        'user_id',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Kirim manual ke alamat selain email pesanan. */
    public function scopeManual($query)
    {
        return $query->where('jenis', self::JENIS_MANUAL);
    }
}
