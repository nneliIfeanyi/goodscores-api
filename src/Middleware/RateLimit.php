<?php

namespace App\Middleware;

use App\Helpers\Response;

/**
 * Simple file-based rate limiter (no Redis required).
 * Keyed by user id or IP + action name.
 */
class RateLimit
{
    public const AI_OCR_BURST_LIMIT = 5;
    public const AI_OCR_COOLDOWN_SECONDS = 10800;
    public const AI_OCR_BURSTS_PER_DAY = 2;

    /**
    * Allow two bursts of five combined AI/OCR operations per calendar day.
    * The first burst is followed by a three-hour cooldown; the second locks access
    * until the next calendar day.
     */
    public static function attemptAiOcrBurst(string $identity): void
    {
        $dir = __DIR__ . '/../../storage/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . hash('sha256', 'ai_ocr_burst|' . $identity) . '.json';
        $handle = @fopen($file, 'c+');
        if (!$handle) {
            // Do not make AI/OCR unavailable if rate-limit storage is temporarily unwritable.
            return;
        }

        $now = time();
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }

        $raw = stream_get_contents($handle);
        $data = json_decode($raw ?: '', true);
        $data = is_array($data) ? $data : [];
        if (($data['version'] ?? 0) !== 2) {
            $data = [];
        }
        $today = date('Y-m-d');
        if (($data['day'] ?? null) !== $today) {
            $data = ['version' => 2, 'day' => $today, 'count' => 0, 'burst' => 0, 'cooldown_until' => 0, 'locked_until' => 0];
        }
        $cooldownUntil = (int)($data['cooldown_until'] ?? 0);
        $lockedUntil = (int)($data['locked_until'] ?? 0);

        if ($lockedUntil > $now) {
            $retry = $lockedUntil - $now;
            flock($handle, LOCK_UN);
            fclose($handle);
            header('Retry-After: ' . $retry);
            Response::error('You have used your AI/OCR allowance for today. Please try again tomorrow.', 429, [
                'retry_after' => $retry,
            ]);
        }

        if ($cooldownUntil > $now) {
            $retry = $cooldownUntil - $now;
            flock($handle, LOCK_UN);
            fclose($handle);
            header('Retry-After: ' . $retry);
            Response::error('You have reached your limit of 5 AI/OCR uses. Please wait 3 hours before trying again.', 429, [
                'retry_after' => $retry,
                'cooldown_seconds' => self::AI_OCR_COOLDOWN_SECONDS,
            ]);
        }

        // A completed cooldown starts the second and final burst of the day.
        $count = $cooldownUntil > 0 ? 0 : (int)($data['count'] ?? 0);
        $burst = (int)($data['burst'] ?? 0);
        if ($cooldownUntil > 0) {
            $burst += 1;
        }
        if ($count >= self::AI_OCR_BURST_LIMIT) {
            $lockedUntil = $burst >= self::AI_OCR_BURSTS_PER_DAY
                ? strtotime('tomorrow')
                : $now + self::AI_OCR_COOLDOWN_SECONDS;
            $data = ['version' => 2, 'day' => $today, 'count' => $count, 'burst' => $burst, 'cooldown_until' => $burst >= self::AI_OCR_BURSTS_PER_DAY ? 0 : $lockedUntil, 'locked_until' => $burst >= self::AI_OCR_BURSTS_PER_DAY ? $lockedUntil : 0];
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
            header('Retry-After: ' . ($lockedUntil - $now));
            Response::error($burst >= self::AI_OCR_BURSTS_PER_DAY
                ? 'You have used your AI/OCR allowance for today. Please try again tomorrow.'
                : 'You have reached your limit of 5 AI/OCR uses. Please wait 3 hours before trying again.', 429, [
                'retry_after' => $lockedUntil - $now,
                'cooldown_seconds' => $burst >= self::AI_OCR_BURSTS_PER_DAY ? 0 : self::AI_OCR_COOLDOWN_SECONDS,
            ]);
        }

        $newCount = $count + 1;
        $reachedDailyLimit = $newCount >= self::AI_OCR_BURST_LIMIT
            && $burst >= self::AI_OCR_BURSTS_PER_DAY;
        $data = [
            'version' => 2,
            'day' => $today,
            'count' => $newCount,
            'burst' => max(1, $burst),
            'cooldown_until' => $newCount >= self::AI_OCR_BURST_LIMIT && $burst < self::AI_OCR_BURSTS_PER_DAY
                ? $now + self::AI_OCR_COOLDOWN_SECONDS
                : 0,
            'locked_until' => $reachedDailyLimit ? strtotime('tomorrow') : 0,
        ];
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * @param string $action e.g. ocr, pdf, auth
     * @param int $max Maximum hits in the window
     * @param int $windowSeconds Window length
     * @param string|null $identity User id or IP
     */
    public static function attempt(string $action, int $max, int $windowSeconds, ?string $identity = null): void
    {
        $identity = $identity ?: (self::clientIp() . '|' . ($GLOBALS['auth_sub'] ?? 'guest'));
        $dir = __DIR__ . '/../../storage/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . hash('sha256', $action . '|' . $identity) . '.json';
        $now = time();
        $data = ['hits' => [], 'action' => $action];

        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = json_decode($raw ?: '', true);
            if (is_array($decoded) && isset($decoded['hits'])) {
                $data = $decoded;
            }
        }

        // Drop hits outside window
        $data['hits'] = array_values(array_filter(
            $data['hits'] ?? [],
            fn($t) => is_int($t) && ($now - $t) < $windowSeconds
        ));

        if (count($data['hits']) >= $max) {
            $retry = $windowSeconds - ($now - min($data['hits']));
            header('Retry-After: ' . max(1, $retry));
            Response::error('Too many requests. Please wait and try again.', 429, [
                'retry_after' => max(1, $retry),
            ]);
        }

        $data['hits'][] = $now;
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }

    public static function clientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }
}
