<?php
declare(strict_types=1);

return [
    'stateful' => [],
    'guard' => ['web'],
    'expiration' => (int) env('SANCTUM_EXPIRATION', 720),
    'token_prefix' => '',
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
