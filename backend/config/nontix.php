<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Biaya Layanan
    |--------------------------------------------------------------------------
    | Biaya layanan per tiket yang dipotong otomatis (Rp 2.000 default).
    */
    'service_fee' => (int) env('NONTIX_SERVICE_FEE', 2000),

    /*
    |--------------------------------------------------------------------------
    | Batas Waktu Pembayaran
    |--------------------------------------------------------------------------
    | Berapa menit sebuah order pending dapat dibayar sebelum dibatalkan.
    */
    'order_expiry_minutes' => (int) env('NONTIX_ORDER_EXPIRY_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Pencairan Dana Partner
    |--------------------------------------------------------------------------
    | Minimal saldo bersih yang bisa diajukan partner untuk pencairan.
    */
    'min_payout' => (int) env('NONTIX_MIN_PAYOUT', 50000),

    /*
    |--------------------------------------------------------------------------
    | URL Frontend
    |--------------------------------------------------------------------------
    | Dipakai untuk membuat tautan invoice / e-ticket pada email.
    */
    'frontend_url' => env('NONTIX_FRONTEND_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Mayar Invoice API
    |--------------------------------------------------------------------------
    */
    'mayar' => [
        'base_url' => env('MAYAR_BASE_URL', 'https://api.mayar.id/hl/v1'),
        'api_key' => env('MAYAR_API_KEY'),
        'mode' => env('MAYAR_MODE', 'fake'), // fake | live
        'callback_token' => env('MAYAR_CALLBACK_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mailketing
    |--------------------------------------------------------------------------
    */
    'mailketing' => [
        'base_url' => env('MAILKETING_BASE_URL', 'https://api.mailketing.co.id'),
        'api_token' => env('MAILKETING_API_TOKEN'),
        'mode' => env('MAILKETING_MODE', 'fake'), // fake | live
    ],
];
