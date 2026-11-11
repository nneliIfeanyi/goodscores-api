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

        return self::hydrate($row, $userId, !empty($row['school_id']) ? (int)$row['school_id'] : null);
    }

    public static function findBySchoolId(int $id, int $schoolId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT p.*, s.name AS subject_name, c.name AS class_name, t.name AS term_name,
                   author.name AS author_name, editor.name AS editor_name
            FROM exam_papers p
            LEFT JOIN subjects s ON s.id = p.subject_id
            LEFT JOIN classes c ON c.id = p.class_id
            LEFT JOIN terms t ON t.id = p.term_id
            INNER JOIN users author ON author.id = p.user_id
            LEFT JOIN users editor ON editor.id = p.updated_by
            WHERE p.id = ? AND p.school_id = ?
        ');
        $stmt->execute([$id, $schoolId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        return self::hydrate($row, (int)$row['user_id'], $schoolId);
    }

    private static function hydrate(array $row, int $questionUserId, ?int $schoolId): array
    {
        $row['question_ids'] = json_decode($row['question_ids'] ?? '[]', true);
        $row['header_override'] = $row['header_override'] ? json_decode($row['header_override'], true) : null;
        $row['paper_settings'] = $row['paper_settings'] ? json_decode($row['paper_settings'], true) : null;

        if (is_array($row['paper_settings']['sections'] ?? null)) {
            foreach ($row['paper_settings']['sections'] as &$section) {
                if (!empty($section['passage_id'])) {
                    $section['passage'] = Passage::findAccessible(
                        (int)$section['passage_id'],
                        $questionUserId,
                        $schoolId
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
            $q = Question::findById((int)$qid, $questionUserId, $schoolId);
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

    public static function listBySchool(int $schoolId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT p.id, p.user_id, p.title, p.status, p.total_marks, p.created_at, p.updated_at, p.updated_by,
                   s.name AS subject_name, c.name AS class_name, t.name AS term_name,
                   author.name AS author_name, editor.name AS editor_name,
                   JSON_LENGTH(p.question_ids) AS question_count
            FROM exam_papers p
            INNER JOIN users author ON author.id = p.user_id
            LEFT JOIN users editor ON editor.id = p.updated_by
            LEFT JOIN subjects s ON s.id = p.subject_id
            LEFT JOIN classes c ON c.id = p.class_id
            LEFT JOIN terms t ON t.id = p.term_id
            WHERE p.school_id = ?
            ORDER BY p.updated_at DESC, p.created_at DESC
        ');
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll();
    }

    public static function historyBySchool(int $paperId, int $schoolId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT r.revision_number, r.snapshot, r.created_at, editor.name AS editor_name
            FROM exam_paper_revisions r
            LEFT JOIN users editor ON editor.id = r.changed_by
            WHERE r.paper_id = ? AND r.school_id = ?
            ORDER BY r.revision_number DESC
        ');
        $stmt->execute([$paperId, $schoolId]);
        $history = $stmt->fetchAll();
        foreach ($history as &$revision) {
            $revision['snapshot'] = json_decode($revision['snapshot'] ?? '{}', true) ?: [];
        }
        unset($revision);
        return $history;
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
                (user_id, updated_by, school_id, title, subject_id, class_id, term_id,
                 header_override, paper_settings, question_ids, total_marks, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $data['user_id'],
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
                total_marks = ?, status = ?, updated_by = ?, updated_at = NOW()
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
            $userId,
            $id,
            $userId,
        ]);
    }

    public static function updateBySchool(int $id, int $schoolId, int $changedBy, array $data): bool
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM exam_papers WHERE id = ? AND school_id = ? FOR UPDATE');
            $stmt->execute([$id, $schoolId]);
            $paper = $stmt->fetch();
            if (!$paper) {
                $db->rollBack();
                return false;
            }

            $revisionStmt = $db->prepare('SELECT COALESCE(MAX(revision_number), 0) + 1 FROM exam_paper_revisions WHERE paper_id = ?');
            $revisionStmt->execute([$id]);
            $revisionNumber = (int)$revisionStmt->fetchColumn();
            $snapshot = [
                'title' => $paper['title'],
                'subject_id' => $paper['subject_id'],
                'class_id' => $paper['class_id'],
                'term_id' => $paper['term_id'],
                'header_override' => $paper['header_override'] ? json_decode($paper['header_override'], true) : null,
                'paper_settings' => $paper['paper_settings'] ? json_decode($paper['paper_settings'], true) : null,
                'question_ids' => json_decode($paper['question_ids'] ?? '[]', true),
                'total_marks' => $paper['total_marks'],
                'status' => $paper['status'],
            ];
            $historyStmt = $db->prepare('
                INSERT INTO exam_paper_revisions (paper_id, school_id, revision_number, changed_by, snapshot, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ');
            $historyStmt->execute([$id, $schoolId, $revisionNumber, $changedBy, json_encode($snapshot)]);

            $updateStmt = $db->prepare('
                UPDATE exam_papers SET
                    title = ?, subject_id = ?, class_id = ?, term_id = ?,
                    header_override = ?, paper_settings = ?, question_ids = ?,
                    total_marks = ?, status = ?, updated_by = ?, updated_at = NOW()
                WHERE id = ? AND school_id = ?
            ');
            $updateStmt->execute([
                $data['title'],
                $data['subject_id'] ?? null,
                $data['class_id'] ?? null,
                $data['term_id'] ?? null,
                isset($data['header_override']) ? json_encode($data['header_override']) : null,
                isset($data['paper_settings']) ? json_encode($data['paper_settings']) : null,
                json_encode($data['question_ids'] ?? []),
                $data['total_marks'] ?? null,
                $data['status'] ?? 'draft',
                $changedBy,
                $id,
                $schoolId,
            ]);
            $db->commit();
            return true;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    public static function delete(int $id, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM exam_papers WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }
}
