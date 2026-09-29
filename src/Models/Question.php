<?php

namespace App\Models;

use App\Config\Database;
use PDO;

class Question
{
    public static function findById(int $id, ?int $userId = null, ?int $schoolId = null): ?array
    {
        $db = Database::getInstance();
        $sql = 'SELECT q.*, 
                       s.name AS subject_name, t.name AS topic_name,
                       c.name AS class_name, tm.name AS term_name,
                       p.title AS passage_title, p.body AS passage_body
                FROM questions q
                LEFT JOIN subjects s ON s.id = q.subject_id
                LEFT JOIN topics t ON t.id = q.topic_id
                LEFT JOIN classes c ON c.id = q.class_id
                LEFT JOIN terms tm ON tm.id = q.term_id
                LEFT JOIN passages p ON p.id = q.passage_id
                WHERE q.id = ? AND q.is_deleted = 0';
        $params = [$id];
        if ($userId !== null) {
            if ($schoolId !== null) {
                $sql .= ' AND (q.user_id = ? OR q.school_id = ?)';
                $params[] = $userId;
                $params[] = $schoolId;
            } else {
                $sql .= ' AND q.user_id = ?';
                $params[] = $userId;
            }
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) return null;

        $row['options'] = $row['options'] ? json_decode($row['options'], true) : null;
        $row['diagram_spec'] = $row['diagram_spec'] ? json_decode($row['diagram_spec'], true) : null;
        $row['diagram_request'] = $row['diagram_request'] ? json_decode($row['diagram_request'], true) : null;
        $row['diagram_spec'] = $row['diagram_spec'] ?: $row['diagram_request'];
        $row['images'] = self::getImages((int)$row['id']);
        return $row;
    }

    public static function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $db = Database::getInstance();
        $where = ['q.is_deleted = 0'];
        $params = [];

        // Ownership: own questions, or shared within same school
        if (!empty($filters['school_id']) && !empty($filters['user_id'])) {
            $where[] = '(q.user_id = ? OR q.school_id = ?)';
            $params[] = $filters['user_id'];
            $params[] = $filters['school_id'];
        } elseif (!empty($filters['user_id'])) {
            $where[] = 'q.user_id = ?';
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['subject_id'])) {
            $where[] = 'q.subject_id = ?';
            $params[] = $filters['subject_id'];
        }
        if (!empty($filters['class_id'])) {
            $where[] = 'q.class_id = ?';
            $params[] = $filters['class_id'];
        }
        if (!empty($filters['term_id'])) {
            $where[] = 'q.term_id = ?';
            $params[] = $filters['term_id'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'q.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['search'])) {
            $where[] = 'q.body LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        $sql = 'SELECT q.id, q.subject_id, q.class_id, q.term_id, q.type, q.content_type, q.body,
                       q.options, q.answer, q.diagram_spec, q.diagram_request, q.marks, q.difficulty, q.created_at, q.updated_at, q.offline_id,
                       s.name AS subject_name, c.name AS class_name, tm.name AS term_name,
                       q.passage_id, p.title AS passage_title,
                       (SELECT COUNT(*) FROM question_images qi WHERE qi.question_id = q.id) AS image_count
                FROM questions q
                LEFT JOIN subjects s ON s.id = q.subject_id
                LEFT JOIN classes c ON c.id = q.class_id
                LEFT JOIN terms tm ON tm.id = q.term_id
                LEFT JOIN passages p ON p.id = q.passage_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY q.created_at DESC
                LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['options'] = $row['options'] ? json_decode($row['options'], true) : null;
            $row['diagram_spec'] = $row['diagram_spec'] ? json_decode($row['diagram_spec'], true) : null;
            $row['diagram_request'] = $row['diagram_request'] ? json_decode($row['diagram_request'], true) : null;
            $row['diagram_spec'] = $row['diagram_spec'] ?: $row['diagram_request'];
        }
        return $rows;
    }

    public static function listForRestore(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT q.*, s.name AS subject_name, t.name AS topic_name,
                   c.name AS class_name, tm.name AS term_name,
                   p.title AS passage_title, p.body AS passage_body
            FROM questions q
            LEFT JOIN subjects s ON s.id = q.subject_id
            LEFT JOIN topics t ON t.id = q.topic_id
            LEFT JOIN classes c ON c.id = q.class_id
            LEFT JOIN terms tm ON tm.id = q.term_id
            LEFT JOIN passages p ON p.id = q.passage_id
            WHERE q.user_id = ? AND q.is_deleted = 0
            ORDER BY q.updated_at DESC, q.id DESC
        ');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['options'] = $row['options'] ? json_decode($row['options'], true) : null;
            $row['diagram_spec'] = $row['diagram_spec'] ? json_decode($row['diagram_spec'], true) : null;
            $row['diagram_request'] = $row['diagram_request'] ? json_decode($row['diagram_request'], true) : null;
            $row['diagram_spec'] = $row['diagram_spec'] ?: $row['diagram_request'];
            $row['images'] = self::getImages((int)$row['id']);
        }
        return $rows;
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO questions
                 (user_id, school_id, subject_id, topic_id, class_id, term_id, passage_id,
                      diagram_request, diagram_spec, content_type, type, body, options, answer, marks, difficulty, offline_id, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $data['user_id'],
            $data['school_id'] ?? null,
            $data['subject_id'] ?? null,
            $data['topic_id'] ?? null,
            $data['class_id'] ?? null,
            $data['term_id'] ?? null,
            $data['passage_id'] ?? null,
            isset($data['diagram_request']) ? json_encode($data['diagram_request']) : null,
            isset($data['diagram_spec']) ? json_encode($data['diagram_spec']) : null,
            $data['content_type'] ?? 'standard',
            $data['type'] ?? 'mcq',
            $data['body'],
            isset($data['options']) ? json_encode($data['options']) : null,
            $data['answer'] ?? null,
            $data['marks'] ?? 1,
            $data['difficulty'] ?? 'medium',
            $data['offline_id'] ?? null,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function findByOfflineId(int $userId, string $offlineId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM questions WHERE user_id = ? AND offline_id = ? AND is_deleted = 0 LIMIT 1');
        $stmt->execute([$userId, $offlineId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            UPDATE questions SET
                subject_id = ?, topic_id = ?, class_id = ?, term_id = ?, passage_id = ?, diagram_request = ?, diagram_spec = ?, content_type = ?,
                type = ?, body = ?, options = ?, answer = ?, marks = ?, difficulty = ?,
                updated_at = NOW()
            WHERE id = ? AND user_id = ? AND is_deleted = 0
        ');
        return $stmt->execute([
            $data['subject_id'] ?? null,
            $data['topic_id'] ?? null,
            $data['class_id'] ?? null,
            $data['term_id'] ?? null,
            $data['passage_id'] ?? null,
            isset($data['diagram_request']) ? json_encode($data['diagram_request']) : null,
            isset($data['diagram_spec']) ? json_encode($data['diagram_spec']) : null,
            $data['content_type'] ?? 'standard',
            $data['type'] ?? 'mcq',
            $data['body'],
            isset($data['options']) ? json_encode($data['options']) : null,
            $data['answer'] ?? null,
            $data['marks'] ?? 1,
            $data['difficulty'] ?? 'medium',
            $id,
            $userId,
        ]);
    }

    public static function softDelete(int $id, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE questions SET is_deleted = 1, updated_at = NOW() WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }

    public static function getImages(int $questionId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM question_images WHERE question_id = ? ORDER BY sort_order, id');
        $stmt->execute([$questionId]);
        return $stmt->fetchAll();
    }

    public static function addImage(int $questionId, array $img): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO question_images
                (question_id, file_path, original_name, mime_type, file_size, type, position, caption, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $questionId,
            $img['file_path'],
            $img['original_name'] ?? null,
            $img['mime_type'] ?? null,
            $img['file_size'] ?? null,
            $img['type'] ?? 'diagram',
            $img['position'] ?? 'after_body',
            $img['caption'] ?? null,
            $img['sort_order'] ?? 0,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function deleteImage(int $imageId, int $userId): bool
    {
        $db = Database::getInstance();
        // Ensure the image belongs to a question owned by the user
        $stmt = $db->prepare('
            DELETE qi FROM question_images qi
            INNER JOIN questions q ON q.id = qi.question_id
            WHERE qi.id = ? AND q.user_id = ?
        ');
        return $stmt->execute([$imageId, $userId]);
    }
}
