<?php

namespace App\Models;

use App\Config\Database;

class Passage
{
    public static function list(array $filters = []): array
    {
        $db = Database::getInstance();
        $where = ['p.user_id = ?'];
        $params = [(int)$filters['user_id']];

        if (!empty($filters['school_id'])) {
            $where[0] = '(p.user_id = ? OR p.school_id = ?)';
            $params[] = (int)$filters['school_id'];
        }
        foreach (['subject_id', 'class_id', 'term_id'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "p.{$field} = ?";
                $params[] = (int)$filters[$field];
            }
        }

        $stmt = $db->prepare('SELECT p.id, p.title, p.body, p.subject_id, p.class_id, p.term_id,
                s.name AS subject_name, c.name AS class_name, t.name AS term_name,
                (SELECT COUNT(*) FROM questions q WHERE q.passage_id = p.id AND q.is_deleted = 0) AS question_count
            FROM passages p
            LEFT JOIN subjects s ON s.id = p.subject_id
            LEFT JOIN classes c ON c.id = p.class_id
            LEFT JOIN terms t ON t.id = p.term_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY p.updated_at DESC, p.created_at DESC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('INSERT INTO passages
            (user_id, school_id, subject_id, class_id, term_id, title, body, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $data['user_id'], $data['school_id'] ?? null, $data['subject_id'] ?? null,
            $data['class_id'] ?? null, $data['term_id'] ?? null, $data['title'], $data['body'],
        ]);
        return (int)$db->lastInsertId();
    }

    public static function findOwned(int $id, int $userId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM passages WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->fetch() ?: null;
    }

    public static function findAccessible(int $id, int $userId, ?int $schoolId = null): ?array
    {
        $db = Database::getInstance();
        $sql = 'SELECT * FROM passages WHERE id = ? AND (user_id = ?';
        $params = [$id, $userId];
        if ($schoolId !== null) {
            $sql .= ' OR school_id = ?';
            $params[] = $schoolId;
        }
        $sql .= ') LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public static function belongsToUser(int $id, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT 1 FROM passages WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);
        return (bool)$stmt->fetchColumn();
    }
}
