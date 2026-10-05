<?php

namespace App\Enums;

enum UserStatus: string
{
    case ACTIVE = 'aktif';
    case INACTIVE = 'nonaktif';
}
