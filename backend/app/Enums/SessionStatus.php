<?php

namespace App\Enums;

enum SessionStatus: string
{
    case ACTIVE = 'aktif';
    case INACTIVE = 'nonaktif';
}
