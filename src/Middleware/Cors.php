<?php

namespace App\Middleware;

class Cors
{
    public static function handle(): void
    {
        $allowed = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*';
        $origins = array_map('trim', explode(',', $allowed));

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        $isLocal = ($_ENV['APP_ENV'] ?? 'local') !== 'production' && $origin !== '' && (
            str_contains($origin, 'localhost') ||
            str_contains($origin, '127.0.0.1')
        );

        if ($allowed === '*' || in_array($origin, $origins, true) || $isLocal) {
            header('Access-Control-Allow-Origin: ' . ($origin !== '' ? $origin : '*'));
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
