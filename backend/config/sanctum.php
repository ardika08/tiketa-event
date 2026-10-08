<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    // Batas ABSOLUT: token mati sendiri setelah N hari sejak dibuat, apa pun
    // aktivitasnya — supaya perangkat yang terlantar tidak hidup selamanya.
    // Nilainya dalam HARI (default 30), dikonversi ke menit karena Sanctum
    // memakai satuan menit. 0 = tanpa batas absolut.
    'expiration' => (int) env('NONTIX_SESSION_ABSOLUTE_DAYS', 30) * 24 * 60,

    /*
    |--------------------------------------------------------------------------
    | Pelacakan last_used_at
    |--------------------------------------------------------------------------
    |
    | Dimatikan (false) dengan sengaja. Kalau dibiarkan true (bawaan Sanctum),
    | Guard akan menulis last_used_at = sekarang pada SETIAP request — dan itu
    | terjadi SEBELUM middleware jalan — sehingga selisih waktu menganggur
    | selalu 0 menit dan sesi tidak akan pernah berakhir. Middleware
    | App\Http\Middleware\EnsureSessionIdle yang mencatat waktunya, setelah
    | pengecekan. Jangan diubah tanpa menyesuaikan middleware tersebut.
    |
    */

    'last_used_at' => false,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
