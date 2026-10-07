<?php

namespace App\Models;

use App\Config\Database;

class Diagram
{
    public static function listByUser(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, user_id, title, description, mode, source, diagram_spec, image_path, mime_type, file_size, created_at, updated_at FROM diagrams WHERE user_id = ? AND is_deleted = 0 ORDER BY updated_at DESC, id DESC');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row['diagram_spec'] = $row['diagram_spec'] ? json_decode($row['diagram_spec'], true) : null;
        }
        return $rows;
    }

    public static function findById(int $id, int $userId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, user_id, title, description, mode, source, diagram_spec, image_path, mime_type, file_size, created_at, updated_at FROM diagrams WHERE id = ? AND user_id = ? AND is_deleted = 0 LIMIT 1');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['diagram_spec'] = $row['diagram_spec'] ? json_decode($row['diagram_spec'], true) : null;
        return $row;
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('INSERT INTO diagrams (user_id, title, description, mode, source, diagram_spec, image_path, mime_type, file_size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            (int)$data['user_id'],
            (string)$data['title'],
            $data['description'] ?? null,
            (string)$data['mode'],
            (string)($data['source'] ?? 'ai'),
            isset($data['diagram_spec']) ? json_encode($data['diagram_spec']) : null,
            $data['image_path'] ?? null,
            $data['mime_type'] ?? null,
            $data['file_size'] ?? null,
        ]);
        return (int)$db->lastInsertId();
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE diagrams SET title = ?, description = ?, mode = ?, source = ?, diagram_spec = ?, image_path = ?, mime_type = ?, file_size = ?, updated_at = NOW() WHERE id = ? AND user_id = ? AND is_deleted = 0');
        return $stmt->execute([
            (string)$data['title'],
            $data['description'] ?? null,
            (string)$data['mode'],
            (string)($data['source'] ?? 'ai'),
            isset($data['diagram_spec']) ? json_encode($data['diagram_spec']) : null,
            $data['image_path'] ?? null,
            $data['mime_type'] ?? null,
            $data['file_size'] ?? null,
            $id,
            $userId,
        ]);
    }

    public static function updateMeta(int $id, int $userId, string $title, ?string $description = null): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE diagrams SET title = ?, description = ?, updated_at = NOW() WHERE id = ? AND user_id = ? AND is_deleted = 0');
        return $stmt->execute([$title, $description, $id, $userId]);
    }

    public static function softDelete(int $id, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE diagrams SET is_deleted = 1, updated_at = NOW() WHERE id = ? AND user_id = ? AND is_deleted = 0');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() === 1;
    }
}
