<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    use HasFactory;

    protected $fillable = [
        'organizer_id',
        'jumlah',
        'status',
        'nama_bank',
        'nomor_rekening',
        'nama_rekening',
        'catatan',
        'catatan_admin',
        'bukti_transfer_url',
        'diproses_at',
        'selesai_at',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'decimal:2',
            'status' => PayoutStatus::class,
            'diproses_at' => 'datetime',
            'selesai_at' => 'datetime',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }
}