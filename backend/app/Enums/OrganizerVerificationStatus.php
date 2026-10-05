<?php

namespace App\Enums;

enum OrganizerVerificationStatus: string
{
    case PENDING = 'pending';
    case VERIFIED = 'terverifikasi';
    case REJECTED = 'ditolak';
}
