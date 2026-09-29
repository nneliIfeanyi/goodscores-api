<?php

namespace App\Models;

use App\Config\Database;
use PDO;

class Meta
{
    public static function subjects(?int $schoolId = null, ?int $teacherId = null): array
    {
        $db = Database::getInstance();
        if ($teacherId !== null) {
            $stmt = $db->prepare('
                SELECT id, name, code, school_id, teacher_id FROM subjects
                WHERE teacher_id = ?
                ORDER BY name
            ');
            $stmt->execute([$teacherId]);
            return $stmt->fetchAll();
        }
        if ($schoolId === null) {
            $stmt = $db->query('
                SELECT id, name, code, school_id FROM subjects
                WHERE school_id IS NULL
                ORDER BY name
            ');
            return $stmt->fetchAll();
        }
        $stmt = $db->prepare('
            SELECT id, name, code, school_id FROM subjects
            WHERE school_id IS NULL OR school_id = ?
            ORDER BY name
        ');
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll();
    }

    public static function classes(?int $schoolId = null, ?int $teacherId = null): array
    {
        $db = Database::getInstance();
        if ($teacherId !== null) {
            $stmt = $db->prepare('
                SELECT id, name, sort_order, school_id, teacher_id FROM classes
                WHERE teacher_id = ?
                ORDER BY sort_order, name
            ');
            $stmt->execute([$teacherId]);
            return $stmt->fetchAll();
        }
        if ($schoolId === null) {
            $stmt = $db->query('
                SELECT id, name, sort_order FROM classes
                WHERE school_id IS NULL
                ORDER BY sort_order, name
            ');
            return $stmt->fetchAll();
        }
        $stmt = $db->prepare('
            SELECT id, name, sort_order FROM classes
            WHERE school_id IS NULL OR school_id = ?
            ORDER BY sort_order, name
        ');
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll();
    }

    public static function terms(?int $schoolId = null): array
    {
        $db = Database::getInstance();
        if ($schoolId === null) {
            $stmt = $db->query('
                SELECT id, name, sort_order FROM terms
                WHERE school_id IS NULL
                ORDER BY sort_order, name
            ');
            return $stmt->fetchAll();
        }
        $stmt = $db->prepare('
            SELECT id, name, sort_order FROM terms
            WHERE school_id IS NULL OR school_id = ?
            ORDER BY sort_order, name
        ');
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll();
    }

    public static function topics(int $subjectId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, name FROM topics WHERE subject_id = ? ORDER BY name');
        $stmt->execute([$subjectId]);
        return $stmt->fetchAll();
    }

    public static function subjectBelongsToTeacher(int $subjectId, int $teacherId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT 1 FROM subjects WHERE id = ? AND teacher_id = ? LIMIT 1');
        $stmt->execute([$subjectId, $teacherId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function classBelongsToTeacher(int $classId, int $teacherId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT 1 FROM classes WHERE id = ? AND teacher_id = ? LIMIT 1');
        $stmt->execute([$classId, $teacherId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function topicBelongsToTeacher(int $topicId, int $teacherId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT 1
            FROM topics t
            INNER JOIN subjects s ON s.id = t.subject_id
            WHERE t.id = ? AND s.teacher_id = ?
            LIMIT 1
        ');
        $stmt->execute([$topicId, $teacherId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function termIsAvailable(int $termId, ?int $schoolId = null): bool
    {
        $db = Database::getInstance();
        if ($schoolId === null) {
            $stmt = $db->prepare('SELECT 1 FROM terms WHERE id = ? AND school_id IS NULL LIMIT 1');
            $stmt->execute([$termId]);
        } else {
            $stmt = $db->prepare('SELECT 1 FROM terms WHERE id = ? AND (school_id IS NULL OR school_id = ?) LIMIT 1');
            $stmt->execute([$termId, $schoolId]);
        }
        return (bool)$stmt->fetchColumn();
    }

    public static function createTopic(int $subjectId, string $name): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('INSERT INTO topics (subject_id, name) VALUES (?, ?)');
        $stmt->execute([$subjectId, trim($name)]);
        return (int) $db->lastInsertId();
    }

    public static function createSubject(int $teacherId, ?int $schoolId, string $name, ?string $code = null): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('INSERT INTO subjects (teacher_id, school_id, name, code) VALUES (?, ?, ?, ?)');
        $stmt->execute([$teacherId, $schoolId, trim($name), $code ? trim($code) : null]);
        return (int)$db->lastInsertId();
    }

    public static function updateSubject(int $id, int $teacherId, string $name, ?string $code = null): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE subjects SET name = ?, code = ? WHERE id = ? AND teacher_id = ?');
        return $stmt->execute([trim($name), $code ? trim($code) : null, $id, $teacherId]);
    }

    public static function deleteSubject(int $id, int $teacherId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM subjects WHERE id = ? AND teacher_id = ?');
        return $stmt->execute([$id, $teacherId]);
    }

    public static function createClass(int $teacherId, ?int $schoolId, string $name, int $sortOrder = 0): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('INSERT INTO classes (teacher_id, school_id, name, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$teacherId, $schoolId, trim($name), $sortOrder]);
        return (int)$db->lastInsertId();
    }

    public static function updateClass(int $id, int $teacherId, string $name, int $sortOrder = 0): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE classes SET name = ?, sort_order = ? WHERE id = ? AND teacher_id = ?');
        return $stmt->execute([trim($name), $sortOrder, $id, $teacherId]);
    }

    public static function deleteClass(int $id, int $teacherId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM classes WHERE id = ? AND teacher_id = ?');
        return $stmt->execute([$id, $teacherId]);
    }

    /** Ensure default rows exist (safe to call repeatedly) */
    public static function ensureDefaults(): void
    {
        $db = Database::getInstance();

        $count = (int) $db->query('SELECT COUNT(*) FROM subjects WHERE school_id IS NULL')->fetchColumn();
        if ($count === 0) {
            $db->exec("
                INSERT INTO subjects (id, school_id, name, code) VALUES
                (1, NULL, 'Mathematics', 'MTH'),
                (2, NULL, 'English Language', 'ENG'),
                (3, NULL, 'Basic Science', 'BSC'),
                (4, NULL, 'Biology', 'BIO'),
                (5, NULL, 'Chemistry', 'CHM'),
                (6, NULL, 'Physics', 'PHY'),
                (7, NULL, 'Civic Education', 'CIV'),
                (8, NULL, 'Social Studies', 'SOS'),
                (9, NULL, 'Computer Studies', 'CMP'),
                (10, NULL, 'Nursery / General', 'NUR')
            ");
        }

        $count = (int) $db->query('SELECT COUNT(*) FROM classes WHERE school_id IS NULL')->fetchColumn();
        if ($count === 0) {
            $db->exec("
                INSERT INTO classes (id, school_id, name, sort_order) VALUES
                (1, NULL, 'Nursery 1', 1),
                (2, NULL, 'Nursery 2', 2),
                (3, NULL, 'Primary 1', 3),
                (4, NULL, 'Primary 2', 4),
                (5, NULL, 'Primary 3', 5),
                (6, NULL, 'Primary 4', 6),
                (7, NULL, 'Primary 5', 7),
                (8, NULL, 'Primary 6', 8),
                (9, NULL, 'JSS 1', 9),
                (10, NULL, 'JSS 2', 10),
                (11, NULL, 'JSS 3', 11),
                (12, NULL, 'SS 1', 12),
                (13, NULL, 'SS 2', 13),
                (14, NULL, 'SS 3', 14)
            ");
        }

        $count = (int) $db->query('SELECT COUNT(*) FROM terms WHERE school_id IS NULL')->fetchColumn();
        if ($count === 0) {
            $db->exec("
                INSERT INTO terms (id, school_id, name, sort_order) VALUES
                (1, NULL, '1st Term', 1),
                (2, NULL, '2nd Term', 2),
                (3, NULL, '3rd Term', 3),
                (4, NULL, 'Mid-Term', 4),
                (5, NULL, 'Mock Exam', 5)
            ");
        }
    }
}
