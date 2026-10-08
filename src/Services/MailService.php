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

        $displayName = trim($recipientName) !== '' ? trim($recipientName) : 'there';
        $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $htmlContent = '<!doctype html><html><body style="margin:0;background:#f4f7f7;color:#1f2937;font-family:Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f7;padding:32px 16px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;">'
            . '<tr><td style="background:#0d9488;padding:24px 32px;color:#ffffff;font-size:22px;font-weight:bold;">GoodScores</td></tr>'
            . '<tr><td style="padding:32px;"><p style="margin:0 0 16px;font-size:18px;font-weight:bold;color:#111827;">Reset your password</p>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Hello ' . $safeName . ',</p>'
            . '<p style="margin:0 0 24px;font-size:15px;line-height:1.6;">We received a request to reset your GoodScores password. Use the button below to choose a new password.</p>'
            . '<p style="margin:0 0 24px;text-align:center;"><a href="' . $safeUrl . '" style="display:inline-block;background:#0d9488;color:#ffffff;text-decoration:none;border-radius:8px;padding:13px 24px;font-size:15px;font-weight:bold;">Reset password</a></p>'
            . '<p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#6b7280;">This link expires in 30 minutes and can only be used once.</p>'
            . '<p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#6b7280;">If the button does not work, copy and paste this link into your browser:</p>'
            . '<p style="margin:0 0 24px;word-break:break-all;font-size:12px;line-height:1.5;"><a href="' . $safeUrl . '" style="color:#0f766e;">' . $safeUrl . '</a></p>'
            . '<p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">If you did not request this change, you can safely ignore this email.</p>'
            . '</td></tr><tr><td style="padding:20px 32px;background:#f9fafb;color:#9ca3af;font-size:12px;">GoodScores · Secure account access</td></tr>'
            . '</table></td></tr></table></body></html>';

        $payload = json_encode([
            'sender' => ['name' => $senderName, 'email' => $senderEmail],
            'to' => [['email' => $recipientEmail, 'name' => $recipientName]],
            'subject' => 'Reset your GoodScores password',
            'textContent' => "Hello {$displayName},\n\nWe received a request to reset your GoodScores password.\n\nReset your password: {$resetUrl}\n\nThis link expires in 30 minutes and can only be used once. If you did not request this, you can ignore this email.",
            'htmlContent' => $htmlContent,
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