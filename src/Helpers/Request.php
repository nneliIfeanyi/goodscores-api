<?php

namespace App\Helpers;

class Request
{
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function int(mixed $value, ?int $default = null): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return trim((string)($value ?? $default));
    }
}
