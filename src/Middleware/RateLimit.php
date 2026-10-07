<?php

namespace App\Middleware;

use App\Helpers\Response;

/**
 * Simple file-based rate limiter (no Redis required).
 * Keyed by user id or IP + action name.
 */
class RateLimit
{
    public const ASK_AI_BURST_LIMIT = 5;
    public const ASK_AI_COOLDOWN_SECONDS = 10800;
    public const ASK_AI_BURSTS_PER_DAY = 2;
    public const AI_OCR_BURST_LIMIT = self::ASK_AI_BURST_LIMIT;
    public const AI_OCR_COOLDOWN_SECONDS = self::ASK_AI_COOLDOWN_SECONDS;
    public const AI_OCR_BURSTS_PER_DAY = self::ASK_AI_BURSTS_PER_DAY;
    private const RESERVATION_TTL_SECONDS = 900;

    public static function reserveAskAi(string $identity): string
    {
        $file = self::channelFile('ask_ai', $identity);
        $handle = self::openLocked($file);
        if (!$handle) return self::failOpenToken();

        $now = time();
        $data = self::readState($handle, [
            'version' => 1, 'day' => date('Y-m-d'), 'count' => 0, 'burst' => 0,
            'cooldown_until' => 0, 'locked_until' => 0, 'reservations' => [],
        ]);
        self::resetDailyState($data, $now);
        self::removeExpiredReservations($data, $now);

        if ((int)$data['cooldown_until'] > 0 && (int)$data['cooldown_until'] <= $now) {
            $data['count'] = 0;
            $data['burst'] = (int)$data['burst'] + 1;
            $data['cooldown_until'] = 0;
        }
        $lockedUntil = (int)$data['locked_until'];
        if ($lockedUntil > $now) {
            self::unlock($handle);
            self::reject($lockedUntil - $now, 'You have used all Ask AI calls for today. Please try again tomorrow.');
        }
        if ((int)$data['count'] >= self::ASK_AI_BURST_LIMIT) {
            $retry = (int)$data['cooldown_until'] > $now
                ? (int)$data['cooldown_until'] - $now
                : max(1, strtotime('tomorrow') - $now);
            self::unlock($handle);
            self::reject($retry, (int)$data['burst'] >= self::ASK_AI_BURSTS_PER_DAY
                ? 'You have used all Ask AI calls for today. Please try again tomorrow.'
                : 'You have reached 5 Ask AI calls. Please wait 3 hours before trying again.');
        }

        if ((int)$data['burst'] === 0) $data['burst'] = 1;
        $token = bin2hex(random_bytes(16));
        $data['count']++;
        $data['reservations'][$token] = $now;
        if ((int)$data['count'] >= self::ASK_AI_BURST_LIMIT) {
            if ((int)$data['burst'] >= self::ASK_AI_BURSTS_PER_DAY) {
                $data['locked_until'] = strtotime('tomorrow');
            } else {
                $data['cooldown_until'] = $now + self::ASK_AI_COOLDOWN_SECONDS;
            }
        }
        self::writeState($handle, $data);
        self::unlock($handle);
        return $token;
    }

    public static function reserveDaily(string $channel, string $identity, int $limit): string
    {
        $file = self::channelFile($channel, $identity);
        $handle = self::openLocked($file);
        if (!$handle) return self::failOpenToken();

        $now = time();
        $data = self::readState($handle, [
            'version' => 1, 'day' => date('Y-m-d'), 'count' => 0, 'reservations' => [],
        ]);
        self::resetDailyState($data, $now);
        self::removeExpiredReservations($data, $now);
        if ((int)$data['count'] >= $limit) {
            self::unlock($handle);
            self::reject(max(1, strtotime('tomorrow') - $now), "You have reached today's {$channel} limit. Please try again tomorrow.");
        }

        $token = bin2hex(random_bytes(16));
        $data['count']++;
        $data['reservations'][$token] = $now;
        self::writeState($handle, $data);
        self::unlock($handle);
        return $token;
    }

    public static function complete(string $channel, string $identity, string $token): void
    {
        self::changeReservation($channel, $identity, $token, false);
    }

    public static function release(string $channel, string $identity, string $token): void
    {
        self::changeReservation($channel, $identity, $token, true);
    }

    private static function channelFile(string $channel, string $identity): string
    {
        $dir = __DIR__ . '/../../storage/ratelimit';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . '/' . hash('sha256', $channel . '|' . $identity) . '.json';
    }

    private static function openLocked(string $file)
    {
        $handle = @fopen($file, 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) {
            if ($handle) fclose($handle);
            return null;
        }
        return $handle;
    }

    private static function readState($handle, array $default): array
    {
        rewind($handle);
        $data = json_decode(stream_get_contents($handle) ?: '', true);
        return is_array($data) && ($data['version'] ?? 0) === 1 ? array_merge($default, $data) : $default;
    }

    private static function writeState($handle, array $data): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data));
        fflush($handle);
    }

    private static function resetDailyState(array &$data, int $now): void
    {
        if (($data['day'] ?? null) !== date('Y-m-d', $now)) {
            $data = array_merge($data, ['day' => date('Y-m-d', $now), 'count' => 0, 'burst' => 0, 'cooldown_until' => 0, 'locked_until' => 0, 'reservations' => []]);
        }
        $data['reservations'] = is_array($data['reservations'] ?? null) ? $data['reservations'] : [];
    }

    private static function removeExpiredReservations(array &$data, int $now): void
    {
        foreach ($data['reservations'] as $token => $createdAt) {
            if ($now - (int)$createdAt > self::RESERVATION_TTL_SECONDS) {
                unset($data['reservations'][$token]);
                $data['count'] = max(0, (int)$data['count'] - 1);
            }
        }
        if ((int)($data['count'] ?? 0) < self::ASK_AI_BURST_LIMIT) {
            $data['cooldown_until'] = 0;
            $data['locked_until'] = 0;
        }
    }

    private static function changeReservation(string $channel, string $identity, string $token, bool $release): void
    {
        if ($token === '') return;
        $handle = self::openLocked(self::channelFile($channel, $identity));
        if (!$handle) return;
        $data = self::readState($handle, ['version' => 1, 'day' => date('Y-m-d'), 'count' => 0, 'reservations' => []]);
        if (isset($data['reservations'][$token])) {
            unset($data['reservations'][$token]);
            if ($release) {
                $data['count'] = max(0, (int)$data['count'] - 1);
                if ($channel === 'ask_ai' && (int)$data['count'] < self::ASK_AI_BURST_LIMIT) {
                    $data['cooldown_until'] = 0;
                    $data['locked_until'] = 0;
                }
            }
            self::writeState($handle, $data);
        }
        self::unlock($handle);
    }

    private static function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private static function reject(int $retry, string $message): void
    {
        header('Retry-After: ' . max(1, $retry));
        Response::error($message, 429, ['retry_after' => max(1, $retry)]);
    }

    private static function failOpenToken(): string
    {
        self::reject(60, 'Usage control is temporarily unavailable. Please try again shortly.');
        return '';
    }

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
