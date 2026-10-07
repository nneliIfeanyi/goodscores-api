<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\Auth;
use App\Middleware\RateLimit;
use App\Models\User;
use App\Services\CreditService;
use App\Services\AiImageService;
use App\Services\AiQuestionService;
use App\Services\UsageService;

class AiController
{
    public const DAILY_REQUEST_LIMIT = 10;
    public const MAX_QUESTIONS_PER_REQUEST = 10;

    public function generateQuestions(): void
    {
        $auth = Auth::requireAuth();
        RateLimit::attemptAiOcrBurst((string)$auth['sub']);

        $user = User::findById((int)$auth['sub']);
        if (!$user) {
            Response::error('User not found', 404);
        }
        $cost = (int)($_ENV['AI_CREDIT_COST'] ?? 35);
        if (!CreditService::canAffordFor($user, 'ai', $cost)) {
            Response::error('Insufficient credits for Ask AI (need ' . $cost . ')', 402);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $count = (int)($input['count'] ?? 0);
        if ($count < 1 || $count > self::MAX_QUESTIONS_PER_REQUEST) {
            Response::error('Choose between 1 and ' . self::MAX_QUESTIONS_PER_REQUEST . ' questions');
        }

        $focus = trim((string)($input['focus'] ?? ''));
        $topic = trim((string)($input['topic'] ?? ''));
        if (mb_strlen($focus) > 500) {
            Response::error('Additional instructions must be 500 characters or fewer');
        }
        if (mb_strlen($topic) > 120) {
            Response::error('Topic must be 120 characters or fewer');
        }

        $types = $input['type'] ?? ['mcq'];
        $isMixed = $types === 'mixed';
        if (!is_array($types)) {
            $types = [$types];
        }
        $types = array_values(array_unique(array_filter(array_map('strval', $types))));
        if ($isMixed) {
            $types = ['mcq', 'fill', 'theory'];
        }
        $difficulty = $input['difficulty'] ?? 'medium';
        $includeDiagrams = 'no';
        $contentType = (string)($input['content_type'] ?? 'standard');
        if (!$types || array_diff($types, ['mcq', 'fill', 'theory'])) {
            Response::error('Invalid question type');
        }
        $type = $isMixed ? 'mixed (MCQ, fill in the gap, or theory)' : implode(' or ', $types);
        if (!in_array($difficulty, ['easy', 'medium', 'hard', 'mixed'], true)) {
            Response::error('Invalid difficulty');
        }
        if (!in_array($includeDiagrams, ['no', 'useful', 'yes'], true)) {
            Response::error('Invalid diagram setting');
        }
        if (!in_array($contentType, ['standard', 'comprehension', 'passage', 'prose', 'poetry', 'drama', 'case_study', 'practical', 'data_interpretation'], true)) {
            Response::error('Invalid content type');
        }

        $subjectId = (int)($input['subject_id'] ?? 0);
        $classId = (int)($input['class_id'] ?? 0);
        $termId = (int)($input['term_id'] ?? 0);
        $subjectName = trim((string)($input['subject_name'] ?? ''));
        $className = trim((string)($input['class_name'] ?? ''));
        $termName = trim((string)($input['term_name'] ?? ''));
        if ($subjectName === '' || $className === '' || $termName === '') {
            Response::error('Subject, class, and term are required');
        }

        $result = AiQuestionService::generate([
            'count' => $count,
            'type' => $type,
            'difficulty' => $difficulty,
            'include_diagrams' => $includeDiagrams,
            'content_type' => $contentType,
            'focus' => $focus,
            'topic' => $topic,
            'subject_name' => $subjectName,
            'class_name' => $className,
            'term_name' => $termName,
        ]);
        if (!$result['success']) {
            Response::error($result['error'], $result['status'] ?? 502);
        }

        $deduct = CreditService::deductFor(
            $user,
            'ai',
            $cost,
            'Ask AI question generation',
            'ai_questions_' . bin2hex(random_bytes(16))
        );
        if (!$deduct['success']) {
            Response::error($deduct['message'] ?? 'Credit deduction failed', 402);
        }

        $user = User::findById((int)$auth['sub']);
        $credits = CreditService::getTypedBalanceForResponse($user, 'ai');

        UsageService::log((int)$auth['sub'], 'ai_questions_generated', [
            'count' => $count,
            'subject_id' => $subjectId,
            'class_id' => $classId,
            'term_id' => $termId,
            'model' => $result['model'] ?? null,
        ]);

        Response::success([
            'passage' => $result['passage'] ?? null,
            'questions' => $result['questions'],
            'count' => count($result['questions']),
            'cost' => $cost,
            'credits_left' => $credits['balance'],
            'daily_limit' => self::DAILY_REQUEST_LIMIT,
            'daily_remaining' => max(0, self::DAILY_REQUEST_LIMIT - UsageService::countToday((int)$auth['sub'], 'ai_questions_generated')),
        ], 'Questions generated');
    }

    public function generateDiagram(): void
    {
        $auth = Auth::requireAuth();
        $user = User::findById((int)$auth['sub']);
        if (!$user) Response::error('User not found', 404);

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $description = trim((string)($input['description'] ?? ''));
        $requestedType = trim((string)($input['type'] ?? 'auto'));
        if ($description === '') {
            Response::error('A diagram prompt is required');
        }

        $spec = null;
        if ($requestedType === 'precise_diagram') {
            $svg = AiQuestionService::generateDiagram($description, 'precise_diagram');
            if (!$svg['success']) Response::error($svg['error'], $svg['status'] ?? 502);
            $spec = [
                'type' => 'precise_diagram',
                'description' => $svg['description'] ?? $description,
                'labels' => [],
                'svg' => $svg['svg'],
                'image_prompt' => '',
            ];
        } elseif ($requestedType === 'illustration') {
            $spec = [
                'type' => 'illustration',
                'description' => $description,
                'labels' => [],
                'svg' => '',
                'image_prompt' => $description,
            ];
        } else {
            $resolved = AiQuestionService::resolveDiagramSpec($description);
            if (!$resolved['success']) Response::error($resolved['error'], $resolved['status'] ?? 502);
            $spec = $resolved['spec'];
            if ($spec['type'] === 'precise_diagram' && ($spec['svg'] ?? '') === '') {
                $svg = AiQuestionService::generateDiagram($spec['description'] ?? $description, 'precise_diagram');
                if (!$svg['success']) Response::error($svg['error'], $svg['status'] ?? 502);
                $spec['svg'] = $svg['svg'];
            }
        }

        $isIllustration = ($spec['type'] ?? '') === 'illustration';
        $cost = (int)($_ENV[$isIllustration ? 'AI_IMAGE_CREDIT_COST' : 'AI_DIAGRAM_CREDIT_COST'] ?? ($isIllustration ? 50 : 10));
        if (!CreditService::canAffordFor($user, 'ai', $cost)) {
            Response::error('Insufficient credits for diagram generation (need ' . $cost . ')', 402);
        }

        if ($isIllustration) {
            $preview = AiImageService::generatePreview($spec);
            if (!$preview['success']) {
                Response::error($preview['error'] ?? 'Could not generate illustration', $preview['status'] ?? 502);
            }
            $deduct = CreditService::deductFor($user, 'ai', $cost, 'AI educational illustration draft', 'ai_illustration_preview_' . bin2hex(random_bytes(16)));
            if (!$deduct['success']) Response::error($deduct['message'] ?? 'Credit deduction failed', 402);
            Response::success([
                'type' => 'illustration',
                'description' => $spec['description'],
                'image_prompt' => $spec['image_prompt'],
                'image_data_url' => $preview['data_url'],
                'mime_type' => $preview['mime_type'],
                'cost' => $cost,
                'credits_left' => $deduct['new_balance'],
            ], 'Illustration generated');
        }

        $deduct = CreditService::deductFor($user, 'ai', $cost, 'AI SVG diagram generation', 'ai_diagram_' . bin2hex(random_bytes(16)));
        if (!$deduct['success']) Response::error($deduct['message'] ?? 'Credit deduction failed', 402);
        Response::success([
            'type' => 'precise_diagram',
            'description' => $spec['description'],
            'svg' => $spec['svg'],
            'cost' => $cost,
            'credits_left' => $deduct['new_balance'],
        ], 'Diagram generated');
    }

}
