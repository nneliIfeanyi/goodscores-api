<?php

namespace App\Helpers;

class Jwt
{
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function encode(array $payload): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? 'fallback-secret-change-me';
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload['iat'] = time();
        $payload['exp'] = time() + (int)($_ENV['JWT_EXPIRY'] ?? 86400);

        $segments = [
            self::base64UrlEncode(json_encode($header)),
            self::base64UrlEncode(json_encode($payload)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;
        $secret = $_ENV['JWT_SECRET'] ?? 'fallback-secret-change-me';

        $expected = self::base64UrlEncode(
            hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true)
        );

        if (!hash_equals($expected, $sigB64)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!$payload || ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }
}
