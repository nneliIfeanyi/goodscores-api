<?php

namespace App\Models;

use App\Config\Database;
use PDO;

class School
{
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM schools WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByCode(string $code): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM schools WHERE school_code = ? LIMIT 1');
        $stmt->execute([strtoupper(trim($code))]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $code = self::generateCode();
        $stmt = $db->prepare('
            INSERT INTO schools (name, logo, address, contact_email, contact_phone, school_code, paper_settings, credit_balance, is_unlimited, subscription_expiry, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, NULL, NOW())
        ');
        $stmt->execute([
            $data['name'],
            $data['logo'] ?? null,
            $data['address'] ?? null,
            $data['contact_email'] ?? null,
            $data['contact_phone'] ?? null,
            $code,
            json_encode($data['paper_settings'] ?? self::defaultPaperSettings()),
        ]);
        return (int) $db->lastInsertId();
    }

    public static function generateCode(): string
    {
        do {
            $code = 'SCH-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $exists = self::findByCode($code);
        } while ($exists);
        return $code;
    }

    public static function defaultPaperSettings(): array
    {
        return [
            'paper_format' => 'columns',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'margin_top' => 20,
            'margin_bottom' => 20,
            'margin_left' => 15,
            'margin_right' => 15,
            'header_height' => 80,
            'show_logo' => true,
            'show_school_name' => true,
            'show_marks' => true,
            'footer_text' => 'End of Paper',
        ];
    }

    public static function updatePaperSettings(int $schoolId, array $settings): bool
    {
        $school = self::findById($schoolId);
        if (!$school) return false;
        $current = json_decode($school['paper_settings'] ?? '{}', true) ?: [];
        $merged = array_merge(self::defaultPaperSettings(), $current, $settings);
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE schools SET paper_settings = ? WHERE id = ?');
        return $stmt->execute([json_encode($merged), $schoolId]);
    }

    public static function updateCredits(int $schoolId, int $balance): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE schools SET credit_balance = ? WHERE id = ?');
        return $stmt->execute([$balance, $schoolId]);
    }

    public static function getTeachers(int $schoolId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT u.id, u.name, u.email, u.role, u.credits, u.is_pro, u.offline_unlocked, u.is_active, u.created_at
            FROM users u
            WHERE u.school_id = ? AND u.role IN ("teacher", "school_admin")
            ORDER BY u.name
        ');
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll();
    }
}
