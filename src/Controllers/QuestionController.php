<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\Auth;
use App\Models\Question;
use App\Models\User;
use App\Models\Meta;
use App\Models\Passage;
use App\Services\AiImageService;
use App\Services\AiQuestionService;
use App\Services\CreditService;

class QuestionController
{
    public function index(): void
    {
        $auth = Auth::requireAuth();
        $userId = (int)$auth['sub'];

        $filters = [
            'user_id'    => $userId,
            'subject_id' => $_GET['subject_id'] ?? null,
            'class_id'   => $_GET['class_id'] ?? null,
            'term_id'    => $_GET['term_id'] ?? null,
            'type'       => $_GET['type'] ?? null,
            'search'     => $_GET['search'] ?? null,
        ];

        // School teachers can also see questions from the same school (optional shared bank)
        if (!empty($auth['school_id'])) {
            $filters['school_id'] = (int)$auth['school_id'];
        }

        $limit  = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $list = Question::list($filters, $limit, $offset);
        Response::success($list);
    }

    public function show(int $id): void
    {
        $auth = Auth::requireAuth();
        $q = Question::findById($id, (int)$auth['sub']);
        if (!$q) {
            // Allow viewing school-shared questions
            $q = Question::findById($id);
            if (!$q || (int)($q['school_id'] ?? 0) !== (int)($auth['school_id'] ?? -1)) {
                Response::error('Question not found', 404);
            }
        }
        Response::success($q);
    }

    public function store(): void
    {
        $auth = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $offlineId = trim((string)($input['offline_id'] ?? ''));

        if ($offlineId !== '') {
            $existing = Question::findByOfflineId((int)$auth['sub'], $offlineId);
            if ($existing) {
                Response::success($existing, 'Question already synced');
            }
        }

        $body = trim(strip_tags($input['body'] ?? '', '<p><br><strong><b><em><i><u><ol><ul><li>'));
        if ($body === '') {
            Response::error('Question body is required');
        }

        $type = $input['type'] ?? 'mcq';
        if (!in_array($type, ['mcq', 'fill', 'theory'], true)) {
            Response::error('Invalid question type');
        }

        if ($type === 'mcq') {
            $options = $input['options'] ?? [];
            if (!is_array($options) || count($options) < 2) {
                Response::error('MCQ requires at least 2 options');
            }
        }

        $contentType = self::contentType($input['content_type'] ?? 'standard');

        if (!empty($input['ai_generated']) && self::hasDuplicate((int)$auth['sub'], $body)) {
            Response::error('This question already exists in your question bank', 409);
        }

        self::validateReferences($input, (int)$auth['sub'], $auth['school_id'] ?? null);

        $user = User::findById((int)$auth['sub']);
        $id = Question::create([
            'user_id'     => (int)$auth['sub'],
            'school_id'   => $user['school_id'] ?? null,
            'subject_id'  => $input['subject_id'] ?? null,
            'topic_id'    => $input['topic_id'] ?? null,
            'class_id'    => $input['class_id'] ?? null,
            'term_id'     => $input['term_id'] ?? null,
                'passage_id'  => $input['passage_id'] ?? null,
                'content_type' => $contentType,
                'diagram_request' => $input['diagram_request'] ?? null,
                'diagram_spec' => AiQuestionService::normalizeDiagramSpec($input['diagram_spec'] ?? $input['diagram_request'] ?? null),
            'type'        => $type,
            'body'        => $body,
            'options'     => $input['options'] ?? null,
            'answer'      => $input['answer'] ?? null,
            'marks'       => $input['marks'] ?? 1,
            'difficulty'  => $input['difficulty'] ?? 'medium',
            'offline_id'  => $offlineId !== '' ? $offlineId : null,
        ]);

        // Optional images (base64 or already uploaded paths)
        if (!empty($input['images']) && is_array($input['images'])) {
            foreach ($input['images'] as $i => $img) {
                if (empty($img['file_path'])) continue;
                Question::addImage($id, [
                    'file_path'     => $img['file_path'],
                    'original_name' => $img['original_name'] ?? null,
                    'mime_type'     => $img['mime_type'] ?? null,
                    'type'          => $img['type'] ?? 'diagram',
                    'position'      => $img['position'] ?? 'after_body',
                    'caption'       => $img['caption'] ?? null,
                    'sort_order'    => $i,
                ]);
            }
        }

        $question = Question::findById($id);
        Response::success($question, 'Question created', 201);
    }

    private static function hasDuplicate(int $userId, string $body): bool
    {
        $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $body) ?? $body));
        $matches = Question::list(['user_id' => $userId, 'search' => $body], 100, 0);
        foreach ($matches as $match) {
            $candidate = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $match['body'] ?? '') ?? ''));
            if ($candidate === $normalized) return true;
        }
        return false;
    }

    public function update(int $id): void
    {
        $auth = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $body = trim(strip_tags($input['body'] ?? '', '<p><br><strong><b><em><i><u><ol><ul><li>'));
        if ($body === '') {
            Response::error('Question body is required');
        }

        self::validateReferences($input, (int)$auth['sub'], $auth['school_id'] ?? null);
        $contentType = self::contentType($input['content_type'] ?? 'standard');

        $ok = Question::update($id, (int)$auth['sub'], [
            'subject_id'  => $input['subject_id'] ?? null,
            'topic_id'    => $input['topic_id'] ?? null,
            'class_id'    => $input['class_id'] ?? null,
            'term_id'     => $input['term_id'] ?? null,
                'passage_id'  => $input['passage_id'] ?? null,
                'content_type' => $contentType,
                'diagram_request' => $input['diagram_request'] ?? null,
                'diagram_spec' => AiQuestionService::normalizeDiagramSpec($input['diagram_spec'] ?? $input['diagram_request'] ?? null),
            'type'        => $input['type'] ?? 'mcq',
            'body'        => $body,
            'options'     => $input['options'] ?? null,
            'answer'      => $input['answer'] ?? null,
            'marks'       => $input['marks'] ?? 1,
            'difficulty'  => $input['difficulty'] ?? 'medium',
        ]);

        if (!$ok) {
            Response::error('Question not found or not owned by you', 404);
        }

        Response::success(Question::findById($id), 'Question updated');
    }

    private static function validateReferences(array $input, int $userId, mixed $schoolId): void
    {
        $subjectId = (int)($input['subject_id'] ?? 0);
        if ($subjectId && !Meta::subjectBelongsToTeacher($subjectId, $userId)) {
            Response::error('Invalid subject for this teacher');
        }

        $classId = (int)($input['class_id'] ?? 0);
        if ($classId && !Meta::classBelongsToTeacher($classId, $userId)) {
            Response::error('Invalid class for this teacher');
        }

        $topicId = (int)($input['topic_id'] ?? 0);
        if ($topicId && !Meta::topicBelongsToTeacher($topicId, $userId)) {
            Response::error('Invalid topic for this teacher');
        }

        $termId = (int)($input['term_id'] ?? 0);
        if ($termId && !Meta::termIsAvailable($termId, $schoolId !== null ? (int)$schoolId : null)) {
            Response::error('Invalid term');
        }

        $passageId = (int)($input['passage_id'] ?? 0);
        if ($passageId && !Passage::belongsToUser($passageId, $userId)) {
            Response::error('Invalid passage for this teacher');
        }
    }

    private static function contentType(mixed $value): string
    {
        $value = (string)$value;
        $allowed = ['standard', 'comprehension', 'passage', 'prose', 'poetry', 'drama', 'case_study', 'practical', 'data_interpretation'];
        if (!in_array($value, $allowed, true)) {
            Response::error('Invalid content type');
        }
        return $value;
    }

    public function destroy(int $id): void
    {
        $auth = Auth::requireAuth();
        $ok = Question::softDelete($id, (int)$auth['sub']);
        if (!$ok) {
            Response::error('Question not found', 404);
        }
        Response::success(null, 'Question deleted');
    }

    /** Upload image for a question – base64, max 5MB, image types only */
    public function uploadImage(int $id): void
    {
        $auth = Auth::requireAuth();
        $q = Question::findById($id, (int)$auth['sub']);
        if (!$q) {
            Response::error('Question not found', 404);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $base64 = $input['image'] ?? '';
        if (!$base64) {
            Response::error('image (base64) required');
        }

        if (preg_match('/^data:(image\/[a-zA-Z0-9+.-]+);base64,/', $base64, $m)) {
            $mime = strtolower($m[1]);
            $base64 = substr($base64, strpos($base64, ',') + 1);
        } else {
            $mime = strtolower($input['mime_type'] ?? 'image/jpeg');
        }

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            Response::error('Only JPEG, PNG, GIF or WebP images are allowed');
        }

        $bin = base64_decode($base64, true);
        if ($bin === false) {
            Response::error('Invalid base64 image');
        }

        $maxBytes = 5 * 1024 * 1024; // 5MB
        if (strlen($bin) > $maxBytes) {
            Response::error('Image too large (max 5MB)');
        }

        // Basic magic-byte check
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($bin);
        if ($detected && !isset($allowed[$detected]) && !str_starts_with($detected, 'image/')) {
            Response::error('File does not look like a valid image');
        }

        $ext = $allowed[$mime];
        $dir = __DIR__ . '/../../storage/uploads/questions/' . $id;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = uniqid('img_', true) . '.' . $ext;
        $fullPath = $dir . '/' . $filename;
        if (file_put_contents($fullPath, $bin) === false) {
            Response::error('Failed to save image', 500);
        }

        $relative = 'uploads/questions/' . $id . '/' . $filename;
        $imgId = Question::addImage($id, [
            'file_path'     => $relative,
            'original_name' => $input['original_name'] ?? $filename,
            'mime_type'     => $mime,
            'file_size'     => strlen($bin),
            'type'          => $input['type'] ?? 'diagram',
            'position'      => $input['position'] ?? 'after_body',
            'caption'       => $input['caption'] ?? null,
            'sort_order'    => (int)($input['sort_order'] ?? 0),
        ]);

        Response::success([
            'id'        => $imgId,
            'file_path' => $relative,
            'url'       => '/storage/' . $relative,
        ], 'Image uploaded', 201);
    }

    public function generateIllustration(int $id): void
    {
        $auth = Auth::requireAuth();
        $question = Question::findById($id, (int)$auth['sub']);
        if (!$question) Response::error('Question not found', 404);
        $spec = AiQuestionService::normalizeDiagramSpec($question['diagram_spec'] ?? $question['diagram_request'] ?? null);
        if (!$spec || $spec['type'] !== 'illustration') {
            Response::error('This question does not request an illustration');
        }
        foreach ($question['images'] ?? [] as $image) {
            if (($image['type'] ?? '') === 'ai_illustration') {
                Response::success([
                    'id' => $image['id'],
                    'file_path' => $image['file_path'],
                    'url' => '/storage/' . ltrim($image['file_path'], '/'),
                ], 'Illustration already generated');
            }
        }

        $user = User::findById((int)$auth['sub']);
        $cost = (int)($_ENV['AI_IMAGE_CREDIT_COST'] ?? 50);
        if (!CreditService::canAfford($user, $cost)) {
            Response::error('Insufficient credits for illustration (need ' . $cost . ')', 402);
        }
        $generated = AiImageService::generateAndStore($question, $spec, $id);
        if (!$generated['success']) Response::error($generated['error'], $generated['status'] ?? 502);
        $deduct = CreditService::deduct($user, $cost, 'AI educational illustration', 'ai_image_' . $id);
        if (!$deduct['success']) Response::error($deduct['message'] ?? 'Credit deduction failed', 402);
        Response::success(array_merge($generated['image'], [
            'cost' => $cost,
            'credits_left' => $deduct['new_balance'],
        ]), 'Illustration generated', 201);
    }
}

