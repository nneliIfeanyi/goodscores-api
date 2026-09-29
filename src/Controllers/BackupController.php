<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Middleware\Auth;
use App\Models\Meta;
use App\Models\Passage;
use App\Models\Question;
use App\Models\User;
use App\Services\AiQuestionService;
use App\Services\CreditService;

class BackupController
{
    public function restoreQuestions(): void
    {
        $auth = Auth::requireAuth();
        $questions = Question::listForRestore((int)$auth['sub']);
        Response::success([
            'questions' => $questions,
            'count' => count($questions),
            'restored_at' => gmdate('c'),
        ], 'Question bank ready to restore');
    }

    public function questions(): void
    {
        $auth = Auth::requireAuth();
        $userId = (int)$auth['sub'];
        $input = Request::json();
        $items = $input['questions'] ?? [];
        if (!is_array($items) || count($items) > 500) Response::error('Invalid backup payload');
        $billableCount = count(array_filter($items, static fn ($item) => empty($item['deleted'])));

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $revision = $this->nextRevision($db, $userId);
            $user = User::findById($userId);
            if (!$user) Response::error('User not found', 404);
            $creditResult = ['success' => true, 'new_balance' => CreditService::getTypedBalanceForResponse($user, 'backup')['balance']];
            if ($billableCount > 0) {
                $creditResult = CreditService::deductFor(
                    $user,
                    'backup',
                    $billableCount,
                    'Question bank backup (' . $billableCount . ' questions)',
                    'backup_' . $userId . '_' . $revision
                );
                if (!$creditResult['success']) {
                    Response::error('Insufficient credits for question bank backup (need ' . $billableCount . ')', 402);
                }
            }
            $processed = [];
            foreach ($items as $item) {
                $offlineId = trim((string)($item['offline_id'] ?? ''));
                if ($offlineId === '' || strlen($offlineId) > 64) Response::error('Each backup item needs a valid local ID');
                if (!empty($item['deleted'])) {
                    $statement = $db->prepare('UPDATE questions SET is_deleted = 1, updated_at = NOW() WHERE user_id = ? AND (offline_id = ? OR id = ?)');
                    $statement->execute([$userId, $offlineId, (int)($item['id'] ?? 0)]);
                    $processed[] = ['offline_id' => $offlineId, 'deleted' => true];
                    continue;
                }

                $body = trim(strip_tags((string)($item['body'] ?? ''), '<p><br><strong><b><em><i><u><ol><ul><li>'));
                if ($body === '') Response::error('Question body is required');
                $existingStatement = $db->prepare('SELECT id FROM questions WHERE user_id = ? AND (offline_id = ? OR id = ?) LIMIT 1');
                $existingStatement->execute([$userId, $offlineId, (int)($item['id'] ?? 0)]);
                $existing = $existingStatement->fetch() ?: null;
                $schoolId = isset($user['school_id']) ? (int)$user['school_id'] : null;
                $subjectId = self::serverId($item['subject_id'] ?? null);
                $classId = self::serverId($item['class_id'] ?? null);
                $topicId = self::serverId($item['topic_id'] ?? null);
                $termId = self::serverId($item['term_id'] ?? null);
                $passageId = self::serverId($item['passage_id'] ?? null);
                $data = [
                    // Local metadata IDs may not exist in this server database.
                    'subject_id' => $subjectId && Meta::subjectBelongsToTeacher($subjectId, $userId) ? $subjectId : null,
                    'topic_id' => $topicId && Meta::topicBelongsToTeacher($topicId, $userId) ? $topicId : null,
                    'class_id' => $classId && Meta::classBelongsToTeacher($classId, $userId) ? $classId : null,
                    'term_id' => $termId && Meta::termIsAvailable($termId, $schoolId) ? $termId : null,
                    'passage_id' => $passageId && Passage::belongsToUser($passageId, $userId) ? $passageId : null,
                    'content_type' => $item['content_type'] ?? 'standard',
                    'diagram_request' => $item['diagram_request'] ?? null,
                    'diagram_spec' => AiQuestionService::normalizeDiagramSpec($item['diagram_spec'] ?? null),
                    'type' => $item['type'] ?? 'mcq',
                    'body' => $body,
                    'options' => $item['options'] ?? null,
                    'answer' => $item['answer'] ?? null,
                    'marks' => $item['marks'] ?? 1,
                    'difficulty' => $item['difficulty'] ?? 'medium',
                ];
                if ($existing) {
                    Question::update((int)$existing['id'], $userId, $data);
                    $serverId = (int)$existing['id'];
                } else {
                    $serverId = Question::create(array_merge($data, [
                        'user_id' => $userId,
                        'school_id' => $user['school_id'] ?? null,
                        'offline_id' => $offlineId,
                    ]));
                }
                if (!empty($item['_pending_image'])) {
                    $this->storePendingImage($serverId, $item['_pending_image'], $item['images'][0] ?? []);
                }
                $processed[] = ['offline_id' => $offlineId, 'id' => $serverId];
            }
            $statement = $db->prepare('INSERT INTO question_bank_backups (user_id, revision, question_count) VALUES (?, ?, ?)');
            $statement->execute([$userId, $revision, count($items)]);
            $db->commit();
            Response::success([
                'revision' => $revision,
                'processed' => $processed,
                'backed_up_at' => gmdate('c'),
                'cost' => $billableCount,
                'credits_left' => $creditResult['new_balance'],
            ], 'Question bank backup complete');
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Question bank backup failed for user ' . $userId . ': ' . $error->getMessage());
            $message = str_contains(strtolower($error->getMessage()), 'question_bank_backups')
                ? 'Question bank backup is not enabled on the server. Apply migration 004_add_manual_question_backup.sql.'
                : 'Question bank backup failed. No questions or credits were changed.';
            $extra = [];
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true' && ($_ENV['APP_ENV'] ?? 'local') !== 'production') {
                $extra['debug_message'] = $error->getMessage();
            }
            Response::error($message, 500, $extra);
        }
    }

    public function status(): void
    {
        $auth = Auth::requireAuth();
        $db = Database::getInstance();
        $statement = $db->prepare('SELECT revision, created_at FROM question_bank_backups WHERE user_id = ? ORDER BY revision DESC LIMIT 1');
        $statement->execute([(int)$auth['sub']]);
        Response::success($statement->fetch() ?: ['revision' => 0, 'created_at' => null]);
    }

    private function nextRevision(\PDO $db, int $userId): int
    {
        $statement = $db->prepare('SELECT COALESCE(MAX(revision), 0) + 1 FROM question_bank_backups WHERE user_id = ? FOR UPDATE');
        $statement->execute([$userId]);
        return (int)$statement->fetchColumn();
    }

    private static function serverId(mixed $value): ?int
    {
        if ($value === null || $value === '' || !ctype_digit((string)$value)) return null;
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    private function storePendingImage(int $questionId, string $dataUrl, array $metadata = []): void
    {
        if (!preg_match('/^data:(image\/(?:jpeg|jpg|png|gif|webp));base64,(.+)$/s', $dataUrl, $matches)) {
            throw new \RuntimeException('Invalid question image format');
        }
        $mime = strtolower($matches[1]) === 'image/jpg' ? 'image/jpeg' : strtolower($matches[1]);
        $binary = base64_decode($matches[2], true);
        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
            throw new \RuntimeException('Question image is invalid or too large');
        }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        if ($detected && $detected !== $mime) {
            throw new \RuntimeException('Question image content does not match its format');
        }
        $directory = __DIR__ . '/../../storage/uploads/questions/' . $questionId;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create question image storage');
        }
        $filename = uniqid('img_', true) . '.jpg';
        $path = $directory . '/' . $filename;
        if (file_put_contents($path, $binary) === false) {
            throw new \RuntimeException('Could not save question image');
        }
        Question::addImage($questionId, [
            'file_path' => 'uploads/questions/' . $questionId . '/' . $filename,
            'original_name' => $metadata['original_name'] ?? 'Question image',
            'mime_type' => $mime,
            'file_size' => strlen($binary),
            'type' => $metadata['type'] ?? 'diagram',
            'position' => $metadata['position'] ?? 'after_body',
            'sort_order' => (int)($metadata['sort_order'] ?? 0),
        ]);
    }
}
