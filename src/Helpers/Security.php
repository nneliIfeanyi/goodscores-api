<?php

namespace App\Helpers;

class Security
{
    public static function headers(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-XSS-Protection: 1; mode=block');
        // API is JSON-only; tighten a bit
        if (($_ENV['APP_ENV'] ?? 'local') === 'production') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function assertProductionSecrets(): void
    {
        if (($_ENV['APP_ENV'] ?? 'local') !== 'production') {
            return;
        }
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '' || str_contains($secret, 'change-this') || strlen($secret) < 32) {
            Response::error('Server misconfigured: JWT_SECRET must be a strong secret in production', 500);
        }
    }

    /** Strip tags / control chars from plain text fields */
    public static function plain(string $value, int $maxLen = 5000): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;
        if (mb_strlen($value) > $maxLen) {
            $value = mb_substr($value, 0, $maxLen);
        }
        return trim($value);
    }
}
