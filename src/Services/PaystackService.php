<?php

namespace App\Services;

use RuntimeException;

class PaystackService
{
    private const API_URL = 'https://api.paystack.co';

    public static function initialize(string $email, int $amountKobo, array $metadata, ?string $callbackUrl = null): array
    {
        $payload = ['email' => $email, 'amount' => $amountKobo, 'currency' => 'NGN', 'metadata' => $metadata];
        if ($callbackUrl) {
            $payload['callback_url'] = $callbackUrl;
        }
        return self::request('/transaction/initialize', $payload);
    }

    public static function verify(string $reference): array
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $reference)) {
            throw new RuntimeException('Invalid payment reference');
        }
        return self::request('/transaction/verify/' . rawurlencode($reference));
    }

    private static function request(string $path, ?array $payload = null): array
    {
        $secret = trim((string)($_ENV['PAYSTACK_SECRET_KEY'] ?? ''));
        if ($secret === '') {
            throw new RuntimeException('Payment gateway is not configured');
        }

        $ch = curl_init(self::API_URL . $path);
        if ($ch === false) {
            throw new RuntimeException('Unable to connect to payment gateway');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret, 'Content-Type: application/json'],
        ];
        if ($payload !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Unable to connect to payment gateway');
        }
        $response = json_decode($body, true);
        if (!is_array($response) || $status < 200 || $status >= 300 || empty($response['status'])) {
            throw new RuntimeException((string)($response['message'] ?? 'Payment gateway request failed'));
        }
        return $response['data'] ?? [];
    }
}