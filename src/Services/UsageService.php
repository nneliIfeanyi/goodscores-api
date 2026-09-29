<?php

namespace App\Services;

use App\Config\Database;

class UsageService
{
    public static function countToday(int $userId, string $event): int
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare('SELECT COUNT(*) FROM usage_logs WHERE user_id = ? AND event = ? AND created_at >= CURDATE()');
            $stmt->execute([$userId, $event]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('UsageService: ' . $e->getMessage());
            return 0;
        }
    }

    public static function log(?int $userId, string $event, array $meta = []): void
    {
        try {
            $db = Database::getInstance();
            // Ensure table exists (lightweight bootstrap)
            $db->exec("
                CREATE TABLE IF NOT EXISTS usage_logs (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NULL,
                    event VARCHAR(80) NOT NULL,
                    meta JSON NULL,
                    ip VARCHAR(45) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_event (event),
                    INDEX idx_user (user_id),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB
            ");
            $stmt = $db->prepare('
                INSERT INTO usage_logs (user_id, event, meta, ip, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ');
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $stmt->execute([
                $userId,
                $event,
                $meta ? json_encode($meta) : null,
                $ip,
            ]);
        } catch (\Throwable $e) {
            // Never break the main request because of analytics
            error_log('UsageService: ' . $e->getMessage());
        }
    }
}
