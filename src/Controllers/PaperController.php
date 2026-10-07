<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\Auth;
use App\Models\Paper;
use App\Models\User;
use App\Models\Question;
use App\Models\Meta;

class PaperController
{
    public function index(): void
    {
        $auth = Auth::requireAuth();
        $list = Paper::listByUser((int)$auth['sub']);
        Response::success($list);
    }

    public function show(int $id): void
    {
        $auth = Auth::requireAuth();
        $paper = Paper::findById($id, (int)$auth['sub']);
        if (!$paper) {
            Response::error('Paper not found', 404);
        }
        Response::success($paper);
    }

    public function store(): void
    {
        $auth = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $title = trim($input['title'] ?? '');
        if ($title === '') {
            Response::error('Paper title is required');
        }

        $questionIds = $input['question_ids'] ?? [];
        if (!is_array($questionIds)) {
            Response::error('question_ids must be an array');
        }
        $questionIds = self::mergeSectionQuestionIds($input, $questionIds);

        [$questionIds, $total] = self::validateReferences(
            $input,
            $questionIds,
            (int)$auth['sub'],
            $auth['school_id'] ?? null
        );

        $user = User::findById((int)$auth['sub']);
        $id = Paper::create([
            'user_id'          => (int)$auth['sub'],
            'school_id'        => $user['school_id'] ?? null,
            'title'            => $title,
            'subject_id'       => $input['subject_id'] ?? null,
            'class_id'         => $input['class_id'] ?? null,
            'term_id'          => $input['term_id'] ?? null,
            'header_override'  => $input['header_override'] ?? null,
            'paper_settings'   => $input['paper_settings'] ?? null,
            'question_ids'     => $questionIds,
            'total_marks'      => $total,
            'status'           => $input['status'] ?? 'draft',
        ]);

        Response::success(Paper::findById($id, (int)$auth['sub']), 'Paper created', 201);
    }

    public function update(int $id): void
    {
        $auth = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $title = trim($input['title'] ?? '');
        if ($title === '') {
            Response::error('Paper title is required');
        }

        $questionIds = $input['question_ids'] ?? [];
        if (!is_array($questionIds)) {
            Response::error('question_ids must be an array');
        }
        $questionIds = self::mergeSectionQuestionIds($input, $questionIds);

        [$questionIds, $total] = self::validateReferences(
            $input,
            $questionIds,
            (int)$auth['sub'],
            $auth['school_id'] ?? null
        );

        $ok = Paper::update($id, (int)$auth['sub'], [
            'title'            => $title,
            'subject_id'       => $input['subject_id'] ?? null,
            'class_id'         => $input['class_id'] ?? null,
            'term_id'          => $input['term_id'] ?? null,
            'header_override'  => $input['header_override'] ?? null,
            'paper_settings'   => $input['paper_settings'] ?? null,
            'question_ids'     => $questionIds,
            'total_marks'      => $total,
            'status'           => $input['status'] ?? 'draft',
        ]);

        if (!$ok) {
            Response::error('Paper not found', 404);
        }
        Response::success(Paper::findById($id, (int)$auth['sub']), 'Paper updated');
    }

    private static function validateReferences(array $input, array $questionIds, int $userId, mixed $schoolId): array
    {
        $subjectId = (int)($input['subject_id'] ?? 0);
        if ($subjectId && !Meta::subjectBelongsToTeacher($subjectId, $userId)) {
            Response::error('Invalid subject for this teacher');
        }

        $classId = (int)($input['class_id'] ?? 0);
        if ($classId && !Meta::classBelongsToTeacher($classId, $userId)) {
            Response::error('Invalid class for this teacher');
        }

        $termId = (int)($input['term_id'] ?? 0);
        if ($termId && !Meta::termIsAvailable($termId, $schoolId !== null ? (int)$schoolId : null)) {
            Response::error('Invalid term');
        }

        $validatedIds = [];
        $total = 0.0;
        foreach ($questionIds as $qid) {
            if (filter_var($qid, FILTER_VALIDATE_INT) === false || (int)$qid < 1) {
                Response::error('question_ids contains an invalid question ID');
            }
            $questionId = (int)$qid;
            $question = Question::findById($questionId, $userId, $schoolId !== null ? (int)$schoolId : null);
            if (!$question) {
                Response::error('One or more questions are not owned by you or no longer exist');
            }
            $validatedIds[] = $questionId;
            $total += (float)$question['marks'];
        }

        return [$validatedIds, $total];
    }

    private static function mergeSectionQuestionIds(array $input, array $questionIds): array
    {
        $settings = $input['paper_settings'] ?? [];
        $sections = is_array($settings) ? ($settings['sections'] ?? []) : [];
        if (!is_array($sections)) return $questionIds;

        $merged = array_map('intval', $questionIds);
        foreach ($sections as $section) {
            if (!is_array($section) || !is_array($section['question_ids'] ?? null)) continue;
            foreach ($section['question_ids'] as $questionId) {
                $questionId = (int)$questionId;
                if ($questionId > 0 && !in_array($questionId, $merged, true)) {
                    $merged[] = $questionId;
                }
            }
        }
        return $merged;
    }

    public function destroy(int $id): void
    {
        $auth = Auth::requireAuth();
        $ok = Paper::delete($id, (int)$auth['sub']);
        if (!$ok) {
            Response::error('Paper not found', 404);
        }
        Response::success(null, 'Paper deleted');
    }

    /**
     * Export paper to PDF (or printable HTML fallback).
     */
    public function export(int $id): void
    {
        $auth = Auth::requireAuth();
        \App\Middleware\RateLimit::attempt('pdf', 8, 600, (string)$auth['sub']);

        $user = User::findById((int)$auth['sub']);
        if (!$user) {
            Response::error('User not found', 404);
        }

        $paper = Paper::findById($id, (int)$auth['sub']);
        if (!$paper) {
            Response::error('Paper not found', 404);
        }
        if (empty($paper['questions'])) {
            Response::error('Paper has no questions to export');
        }

        $result = \App\Services\PdfService::renderPdf($paper, $user);
        if (!$result['success']) {
            Response::error($result['error'] ?? 'PDF export failed', 500);
        }

        $user = User::findById((int)$auth['sub']);
        $credits = \App\Services\CreditService::getUserEffectiveCredits($user);

        \App\Services\UsageService::log((int)$auth['sub'], 'pdf_export', [
            'paper_id' => $id,
            'cost'     => 0,
            'fallback' => !empty($result['fallback']),
        ]);

        Response::success([
            'filename'     => $result['filename'],
            'download_url' => '/storage/' . $result['relative'],
            'fallback'     => !empty($result['fallback']),
            'message'      => $result['message'] ?? 'PDF ready',
            'credits_left' => $credits['balance'],
            'cost'         => 0,
        ], 'Export complete');
    }
}


