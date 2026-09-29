<?php

namespace App\Helpers;

class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(array_merge(['error' => $message], $extra), $status);
    }

    public static function success(mixed $data = null, string $message = 'Success', int $status = 200): void
    {
        $payload = ['message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        self::json($payload, $status);
    }
}
