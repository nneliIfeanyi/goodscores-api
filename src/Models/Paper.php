<?php

namespace App\Models;

use App\Config\Database;
use App\Models\Passage;
use PDO;

class Paper
{
    public static function findById(int $id, int $userId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT p.*, s.name AS subject_name, c.name AS class_name, t.name AS term_name
            FROM exam_papers p
            LEFT JOIN subjects s ON s.id = p.subject_id
            LEFT JOIN classes c ON c.id = p.class_id
            LEFT JOIN terms t ON t.id = p.term_id
            WHERE p.id = ? AND p.user_id = ?
        ');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $row['question_ids'] = json_decode($row['question_ids'] ?? '[]', true);
        $row['header_override'] = $row['header_override'] ? json_decode($row['header_override'], true) : null;
        $row['paper_settings'] = $row['paper_settings'] ? json_decode($row['paper_settings'], true) : null;

        if (is_array($row['paper_settings']['sections'] ?? null)) {
            foreach ($row['paper_settings']['sections'] as &$section) {
                if (!empty($section['passage_id'])) {
                    $section['passage'] = Passage::findAccessible(
                        (int)$section['passage_id'],
                        $userId,
                        !empty($row['school_id']) ? (int)$row['school_id'] : null
                    );
                }
            }
            unset($section);
        }

        // Load full questions in top-level order, including section-only IDs from older saves.
        $questionIds = array_map('intval', is_array($row['question_ids']) ? $row['question_ids'] : []);
        foreach (($row['paper_settings']['sections'] ?? []) as $section) {
            foreach (($section['question_ids'] ?? []) as $questionId) {
                $questionId = (int)$questionId;
                if ($questionId > 0 && !in_array($questionId, $questionIds, true)) {
                    $questionIds[] = $questionId;
                }
            }
        }

        // Load full questions in order
        $questions = [];
        foreach ($questionIds as $qid) {
            $q = Question::findById((int)$qid, $userId, !empty($row['school_id']) ? (int)$row['school_id'] : null);
            if ($q) $questions[] = $q;
        }
        $row['questions'] = $questions;
        return $row;
    }

    public static function listByUser(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT p.id, p.title, p.status, p.total_marks, p.created_at, p.updated_at,
                   s.name AS subject_name, c.name AS class_name, t.name AS term_name,
                   JSON_LENGTH(p.question_ids) AS question_count
            FROM exam_papers p
            LEFT JOIN subjects s ON s.id = p.subject_id
            LEFT JOIN classes c ON c.id = p.class_id
            LEFT JOIN terms t ON t.id = p.term_id
            WHERE p.user_id = ?
            ORDER BY p.updated_at DESC, p.created_at DESC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function listForRestore(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM exam_papers WHERE user_id = ? ORDER BY updated_at DESC, created_at DESC');
        $stmt->execute([$userId]);

        $papers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $paper = self::findById((int)$id, $userId);
            if ($paper) $papers[] = $paper;
        }
        return $papers;
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO exam_papers
                (user_id, school_id, title, subject_id, class_id, term_id,
                 header_override, paper_settings, question_ids, total_marks, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $data['user_id'],
            $data['school_id'] ?? null,
            $data['title'],
            $data['subject_id'] ?? null,
            $data['class_id'] ?? null,
            $data['term_id'] ?? null,
            isset($data['header_override']) ? json_encode($data['header_override']) : null,
            isset($data['paper_settings']) ? json_encode($data['paper_settings']) : null,
            json_encode($data['question_ids'] ?? []),
            $data['total_marks'] ?? null,
            $data['status'] ?? 'draft',
        ]);
        return (int) $db->lastInsertId();
    }

    public static function update(int $id, int $userId, array $data): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            UPDATE exam_papers SET
                title = ?, subject_id = ?, class_id = ?, term_id = ?,
                header_override = ?, paper_settings = ?, question_ids = ?,
                total_marks = ?, status = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ');
        return $stmt->execute([
            $data['title'],
            $data['subject_id'] ?? null,
            $data['class_id'] ?? null,
            $data['term_id'] ?? null,
            isset($data['header_override']) ? json_encode($data['header_override']) : null,
            isset($data['paper_settings']) ? json_encode($data['paper_settings']) : null,
            json_encode($data['question_ids'] ?? []),
            $data['total_marks'] ?? null,
            $data['status'] ?? 'draft',
            $id,
            $userId,
        ]);
    }

    public static function delete(int $id, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM exam_papers WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }
}
