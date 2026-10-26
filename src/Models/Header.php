<?php

namespace App\Models;

use App\Config\Database;
use PDO;

class Header
{
    public static function listByUser(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM paper_headers WHERE user_id = ? ORDER BY is_default DESC, id ASC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id, int $userId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM paper_headers WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findDefault(int $userId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM paper_headers WHERE user_id = ? ORDER BY is_default DESC, id ASC LIMIT 1');
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $userId, array $data): int
    {
        $db = Database::getInstance();
        if (!empty($data['is_default'])) {
            $db->prepare('UPDATE paper_headers SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        }
        $stmt = $db->prepare('
            INSERT INTO paper_headers (user_id, label, school_name, extra_line, logo_path, is_default)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId,
            $data['label'] ?? 'Header',
            $data['school_name'],
            $data['extra_line'] ?? null,
            $data['logo_path'] ?? null,
            !empty($data['is_default']) ? 1 : 0,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        $db = Database::getInstance();
        if (!empty($data['is_default'])) {
            $db->prepare('UPDATE paper_headers SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        }
        $stmt = $db->prepare('
            UPDATE paper_headers SET label = ?, school_name = ?, extra_line = ?, logo_path = ?, is_default = ?
            WHERE id = ? AND user_id = ?
        ');
        return $stmt->execute([
            $data['label'] ?? 'Header',
            $data['school_name'],
            $data['extra_line'] ?? null,
            $data['logo_path'] ?? null,
            !empty($data['is_default']) ? 1 : 0,
            $id,
            $userId,
        ]);
    }

    public static function updateDetailsOnce(int $id, int $userId, string $schoolName, ?string $extraLine): bool
    {
        $stmt = Database::getInstance()->prepare('
            UPDATE paper_headers
            SET label = ?, school_name = ?, extra_line = ?, details_edit_used = 1
            WHERE id = ? AND user_id = ? AND details_edit_used = 0
        ');
        $stmt->execute([$schoolName, $schoolName, $extraLine, $id, $userId]);
        return $stmt->rowCount() === 1;
    }

    public static function updateLogo(int $id, int $userId, string $logoPath): bool
    {
        $stmt = Database::getInstance()->prepare(
            'UPDATE paper_headers SET logo_path = ? WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$logoPath, $id, $userId]);
        return $stmt->rowCount() === 1;
    }

    public static function delete(int $id, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM paper_headers WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }

    public static function count(int $userId): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT COUNT(*) FROM paper_headers WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}
