<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case PAID = 'lunas';
    case CANCELLED = 'dibatalkan';
    case EXPIRED = 'kadaluarsa';

    public function isFinal(): bool
    {
        return $this !== self::PENDING;
    }
}
