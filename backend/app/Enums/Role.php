<?php

namespace App\Enums;

enum Role: string
{
    case ADMIN = 'admin_platform';
    case PARTNER = 'partner';
    case STAFF = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Admin Platform',
            self::PARTNER => 'Penyelenggara',
            self::STAFF => 'Staff',
        };
    }
}
