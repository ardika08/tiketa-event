<?php

namespace App\Enums;

enum PayoutStatus: string
{
    case DIAJUKAN = 'diajukan';
    case DIPROSES = 'diproses';
    case SELESAI = 'selesai';
    case DITOLAK = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::DIAJUKAN => 'Diajukan',
            self::DIPROSES => 'Diproses',
            self::SELESAI => 'Selesai',
            self::DITOLAK => 'Ditolak',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::DIAJUKAN, self::DIPROSES], true);
    }
}