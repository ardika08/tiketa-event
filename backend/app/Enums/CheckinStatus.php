<?php

namespace App\Enums;

enum CheckinStatus: string
{
    case SUCCESS = 'berhasil';
    case FAILED = 'gagal';
}
