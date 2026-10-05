<?php

namespace App\Enums;

enum EventStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'aktif';
    case DONE = 'selesai';
}
