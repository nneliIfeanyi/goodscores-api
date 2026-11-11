<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\Auth;
use App\Models\Meta;
use App\Models\Passage;
use App\Models\User;

class PassageController
{
    public function index(): void
    {
        $auth = Auth::requireAuth();
        $filters = [
            'user_id' => (int)$auth['sub'],
            'school_id' => $auth['school_id'] ?? null,
            'subject_id' => $_GET['subject_id'] ?? null,
            'class_id' => $_GET['class_id'] ?? null,
            'term_id' => $_GET['term_id'] ?? null,
        ];
        Response::success(Passage::list($filters));
    }

    public function store(): void
    {
        $auth = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $title = trim($input['title'] ?? '');
        $body = trim(strip_tags($input['body'] ?? '', '<p><br><strong><b><em><i><u><ol><ul><li>'));
        $offlineId = trim((string)($input['offline_id'] ?? ''));
        if (strlen($offlineId) > 64) {
            Response::error('Invalid local passage ID');
        }
        if ($title === '' || $body === '') {
            Response::error('Passage title and text are required');
        }

        $userId = (int)$auth['sub'];
        $schoolId = $auth['school_id'] ?? null;
        $subjectId = (int)($input['subject_id'] ?? 0);
        if ($subjectId && !Meta::subjectBelongsToTeacher($subjectId, $userId)) {
            if ($offlineId === '') Response::error('Invalid subject for this teacher');
            $subjectId = 0;
        }
        $classId = (int)($input['class_id'] ?? 0);
        if ($classId && !Meta::classBelongsToTeacher($classId, $userId)) {
            if ($offlineId === '') Response::error('Invalid class for this teacher');
            $classId = 0;
        }
        $termId = (int)($input['term_id'] ?? 0);
        if ($termId && !Meta::termIsAvailable($termId, $schoolId !== null ? (int)$schoolId : null)) {
            if ($offlineId === '') Response::error('Invalid term');
            $termId = 0;
        }

        $user = User::findById($userId);
        $id = Passage::create([
            'user_id' => (int)$auth['sub'],
            'school_id' => $user['school_id'] ?? null,
            'subject_id' => $subjectId ?: null,
            'class_id' => $classId ?: null,
            'term_id' => $termId ?: null,
            'offline_id' => $offlineId !== '' ? $offlineId : null,
            'title' => $title,
            'body' => $body,
        ]);
        Response::success(Passage::findOwned($id, (int)$auth['sub']), 'Passage saved', 201);
    }
}
