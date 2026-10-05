<?php

namespace App\Support;

use Illuminate\Support\Str;

class Code
{
    /**
     * Kode order, contoh: NTX-8F2K9A.
     */
    public static function order(): string
    {
        return 'NTX-'.strtoupper(Str::random(6));
    }

    /**
     * Kode tiket, contoh: A7K2-9PLM-3XQ8.
     */
    public static function ticket(): string
    {
        return self::segments(3, 4);
    }

    /**
     * Kode QR per sesi/hari, contoh: Q7K2-9PLM-3XQ8.
     */
    public static function qr(): string
    {
        return 'Q'.self::segments(3, 4);
    }

    private static function segments(int $count, int $length): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $segments = [];

        for ($s = 0; $s < $count; $s++) {
            $segment = '';
            for ($i = 0; $i < $length; $i++) {
                $segment .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $segments[] = $segment;
        }

        return implode('-', $segments);
    }
}
