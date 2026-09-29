<?php

namespace App\Services;

use RuntimeException;

class MailService
{
    public static function sendPasswordReset(string $recipientEmail, string $recipientName, string $resetUrl): void
    {
        $apiKey = trim((string)($_ENV['BREVO_API_KEY'] ?? ''));
        $senderEmail = trim((string)($_ENV['BREVO_SENDER_EMAIL'] ?? ''));
        $senderName = trim((string)($_ENV['BREVO_SENDER_NAME'] ?? 'GoodScores'));
        if ($apiKey === '' || $senderEmail === '') {
            throw new RuntimeException('Email service is not configured');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for email delivery');
        }

        $payload = json_encode([
            'sender' => ['name' => $senderName, 'email' => $senderEmail],
            'to' => [['email' => $recipientEmail, 'name' => $recipientName]],
            'subject' => 'Reset your GoodScores password',
            'htmlContent' => '<p>Hello ' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>We received a request to reset your GoodScores password.</p>'
                . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Reset your password</a></p>'
                . '<p>This link expires in 30 minutes and can only be used once. If you did not request this, you can ignore this email.</p>',
        ], JSON_UNESCAPED_SLASHES);

        $curl = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['accept: application/json', 'api-key: ' . $apiKey, 'content-type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false || $status < 200 || $status >= 300) {
            error_log('Brevo email failed: HTTP ' . $status . ($error ? ' ' . $error : ''));
            throw new RuntimeException('Could not send password reset email');
        }
    }
}