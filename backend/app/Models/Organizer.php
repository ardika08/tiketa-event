<?php

namespace App\Models;

use App\Enums\OrganizerVerificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organizer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'nama_penyelenggara',
        'email',
        'telepon',
        'logo_url',
        'deskripsi',
        'nama_bank',
        'nomor_rekening',
        'nama_rekening',
        'status_verifikasi',
        'status_akun',
    ];

    protected function casts(): array
    {
        return [
            'status_verifikasi' => OrganizerVerificationStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function legal(): HasOne
    {
        return $this->hasOne(OrganizerLegal::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function gates(): HasMany
    {
        return $this->hasMany(Gate::class);
    }

    public function staffs(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function formFields(): HasMany
    {
        return $this->hasMany(FormField::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}
