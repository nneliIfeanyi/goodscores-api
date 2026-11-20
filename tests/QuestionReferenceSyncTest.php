<?php

namespace Tests;

use App\Config\Database;
use App\Controllers\BackupController;
use App\Models\Question;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class QuestionReferenceSyncTest extends TestCase
{
    private PDO $db;
    private mixed $previousDatabase;

    protected function setUp(): void
    {
        $property = new ReflectionProperty(Database::class, 'instance');
        $this->previousDatabase = $property->getValue();
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $property->setValue(null, $this->db);
        $this->db->exec('CREATE TABLE subjects (id INTEGER, teacher_id INTEGER)');
        $this->db->exec('CREATE TABLE classes (id INTEGER, teacher_id INTEGER)');
        $this->db->exec('CREATE TABLE terms (id INTEGER, school_id INTEGER)');
        $this->db->exec('CREATE TABLE questions (id INTEGER, user_id INTEGER, offline_id TEXT, is_deleted INTEGER, subject_id INTEGER, class_id INTEGER, term_id INTEGER)');
        $this->db->exec('INSERT INTO subjects VALUES (10, 7), (11, 8)');
        $this->db->exec('INSERT INTO classes VALUES (20, 7)');
        $this->db->exec('INSERT INTO terms VALUES (30, NULL)');
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Database::class, 'instance'))->setValue(null, $this->previousDatabase);
    }

    public function testBackupRejectsUnresolvedOrForeignReferences(): void
    {
        $validate = new ReflectionMethod(BackupController::class, 'validateReferences');
        $valid = $validate->invoke(null, ['subject_id' => 10, 'class_id' => 20, 'term_id' => 30], 7, null);
        self::assertSame(10, $valid['subject_id']);
        self::assertSame(20, $valid['class_id']);
        self::assertSame(30, $valid['term_id']);
        foreach ([['subject_id' => 'local-subject'], ['subject_id' => 11], ['class_id' => 'local-class'], ['term_id' => 99]] as $invalid) {
            try {
                $validate->invoke(null, $invalid, 7, null);
                self::fail('Invalid references must not be silently removed');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('invalid', $error->getMessage());
            }
        }
    }

    public function testRepairOnlyFillsMissingReferencesOnTheMatchingOwnedQuestion(): void
    {
        $this->db->exec("INSERT INTO questions VALUES (1, 7, 'q1', 0, NULL, 21, NULL), (2, 8, 'q2', 0, NULL, NULL, NULL), (3, 7, NULL, 0, NULL, NULL, NULL), (4, 7, 'deleted', 1, NULL, NULL, NULL)");
        $refs = ['subject_id' => 10, 'class_id' => 20, 'term_id' => 30];
        self::assertTrue(Question::repairMissingReferences(1, 7, 'q1', $refs));
        $row = $this->db->query('SELECT subject_id, class_id, term_id FROM questions WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertEquals(['subject_id' => 10, 'class_id' => 21, 'term_id' => 30], $row);
        self::assertFalse(Question::repairMissingReferences(2, 7, 'q2', $refs));
        self::assertFalse(Question::repairMissingReferences(1, 7, 'wrong-id', $refs));
        self::assertFalse(Question::repairMissingReferences(4, 7, 'deleted', $refs));
        self::assertTrue(Question::repairMissingReferences(3, 7, 'server:3', $refs));
    }
}
