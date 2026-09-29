<?php

namespace App\Models;

use App\Config\Database;

class PasswordReset
{
    public static function invalidateForUser(int $userId): void
    {
        $stmt = Database::getInstance()->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$userId]);
    }

    public static function create(int $userId, string $tokenHash, string $expiresAt): void
    {
        self::invalidateForUser($userId);
        $stmt = Database::getInstance()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $tokenHash, $expiresAt]);
    }

    public static function findValid(string $tokenHash): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function markUsed(int $id): void
    {
        $stmt = Database::getInstance()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }
}