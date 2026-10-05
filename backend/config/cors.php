<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Atur origin frontend yang diizinkan mengakses API. Bila frontend dan
    | backend berada di domain berbeda, tambahkan domain frontend ke
    | CORS_ALLOWED_ORIGINS (pisahkan dengan koma) di .env.
    | Contoh: CORS_ALLOWED_ORIGINS=https://nontix.id,https://www.nontix.id
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
