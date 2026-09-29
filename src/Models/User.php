<?php

namespace App\Models;

use App\Config\Database;
use PDO;

class User
{
    public static function findByEmail(string $email): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        // plan column may not exist on very old DBs – try full insert, fallback
        try {
            $stmt = $db->prepare('
                INSERT INTO users (name, email, password, role, school_id, credits, offline_unlocked, is_pro, plan, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, NOW())
            ');
            $credits = $data['credits'] ?? (int)($_ENV['INITIAL_FREE_CREDITS'] ?? 200);
            $stmt->execute([
                $data['name'],
                $data['email'],
                $data['password'],
                $data['role'] ?? 'individual',
                $data['school_id'] ?? null,
                $credits,
                $data['plan'] ?? 'free',
            ]);
        } catch (\PDOException $e) {
            $stmt = $db->prepare('
                INSERT INTO users (name, email, password, role, school_id, credits, offline_unlocked, is_pro, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, 0, NOW())
            ');
            $stmt->execute([
                $data['name'],
                $data['email'],
                $data['password'],
                $data['role'] ?? 'individual',
                $data['school_id'] ?? null,
                $data['credits'] ?? (int)($_ENV['INITIAL_FREE_CREDITS'] ?? 200),
            ]);
        }
        return (int) $db->lastInsertId();
    }

    public static function updateCredits(int $userId, int $newBalance): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE users SET credits = ? WHERE id = ?');
        return $stmt->execute([$newBalance, $userId]);
    }

        public static function updatePassword(int $userId, string $passwordHash): bool
        {
            $db = Database::getInstance();
            $stmt = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
            return $stmt->execute([$passwordHash, $userId]);
        }

    public static function setPro(int $userId, bool $isPro = true, string $plan = 'pro'): bool
    {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare('UPDATE users SET is_pro = ?, plan = ? WHERE id = ?');
            return $stmt->execute([$isPro ? 1 : 0, $plan, $userId]);
        } catch (\PDOException $e) {
            $stmt = $db->prepare('UPDATE users SET is_pro = ? WHERE id = ?');
            return $stmt->execute([$isPro ? 1 : 0, $userId]);
        }
    }

    public static function setSubscriptionExpiry(int $userId, ?string $expiry): bool
    {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare('UPDATE users SET subscription_expiry = ? WHERE id = ?');
            return $stmt->execute([$expiry, $userId]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    public static function unlockOffline(int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE users SET offline_unlocked = 1 WHERE id = ?');
        return $stmt->execute([$userId]);
    }

    public static function linkToSchool(int $userId, int $schoolId): bool
    {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare('UPDATE users SET school_id = ?, role = ?, is_pro = 1, plan = ? WHERE id = ?');
            return $stmt->execute([$schoolId, 'teacher', 'pro', $userId]);
        } catch (\PDOException $e) {
            $stmt = $db->prepare('UPDATE users SET school_id = ?, role = ?, is_pro = 1 WHERE id = ?');
            return $stmt->execute([$schoolId, 'teacher', $userId]);
        }
    }

    public static function updateProfile(int $userId, array $data): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE users SET name = ? WHERE id = ?');
        return $stmt->execute([$data['name'], $userId]);
    }

    public static function updatePdfSettings(int $userId, float $fontSize, string $fontFamily, bool $showMarks): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE users SET pdf_font_size = ?, pdf_font_family = ?, pdf_show_marks = ? WHERE id = ?');
        return $stmt->execute([$fontSize, $fontFamily, $showMarks ? 1 : 0, $userId]);
    }

    public static function updatePdfPreferences(int $userId, array $settings): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE users SET pdf_settings = ? WHERE id = ?');
        return $stmt->execute([json_encode($settings), $userId]);
    }

    public static function setActive(int $userId, bool $active): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        return $stmt->execute([$active ? 1 : 0, $userId]);
    }

    public static function updateTeacher(int $userId, string $name, string $email, ?string $passwordHash = null): bool
    {
        $db = Database::getInstance();
        if ($passwordHash !== null) {
            $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?');
            return $stmt->execute([$name, $email, $passwordHash, $userId]);
        }
        $stmt = $db->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
        return $stmt->execute([$name, $email, $userId]);
    }

    public static function deleteTeacher(int $userId, int $schoolId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM users WHERE id = ? AND school_id = ? AND role = "teacher"');
        $stmt->execute([$userId, $schoolId]);
        return $stmt->rowCount() === 1;
    }

    public static function deleteIndividual(int $userId): bool
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT id, role, school_id FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user || $user['role'] !== 'individual' || $user['school_id'] !== null) {
                $db->rollBack();
                return false;
            }
            $delete = $db->prepare('DELETE FROM users WHERE id = ?');
            $delete->execute([$userId]);
            $db->commit();
            return $delete->rowCount() === 1;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
