<?php

namespace App\Middleware;

use App\Helpers\Jwt;
use App\Helpers\Response;
use App\Models\User;

class Auth
{
    public static function bearerToken(): ?string
    {
        // Apache / CGI often puts the header in different places
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        // Fallback: some setups only expose via getallheaders()
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public static function user(): ?array
    {
        $token = self::bearerToken();
        if (!$token) {
            return null;
        }
        return Jwt::decode($token);
    }

    public static function requireAuth(): array
    {
        $user = self::user();
        if (!$user || empty($user['sub'])) {
            Response::error('Unauthorized', 401);
        }
        $record = User::findById((int)$user['sub']);
        if (!$record || (array_key_exists('is_active', $record) && !(bool)$record['is_active'])) {
            Response::error('Account is inactive', 403);
        }
        return $user;
    }

    public static function requireRole(array $roles): array
    {
        $user = self::requireAuth();
        if (!in_array($user['role'] ?? '', $roles, true)) {
            Response::error('Forbidden', 403);
        }
        return $user;
    }
}
