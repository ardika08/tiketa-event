<?php

namespace App\Enums;

enum TicketStatus: string
{
    case NOT_CHECKED_IN = 'belum_hadir';
    case CHECKED_IN = 'hadir';
}
