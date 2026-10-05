<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizerLegal extends Model
{
    use HasFactory;

    protected $table = 'organizer_legal';

    protected $fillable = [
        'organizer_id',
        'nama_penanggung_jawab',
        'no_identitas',
        'dokumen_url',
        'tipe_dokumen',
        'status',
        'catatan',
    ];

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }
}
