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
        if ($title === '' || $body === '') {
            Response::error('Passage title and text are required');
        }

        $userId = (int)$auth['sub'];
        $schoolId = $auth['school_id'] ?? null;
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

        $user = User::findById($userId);
        $id = Passage::create([
            'user_id' => (int)$auth['sub'],
            'school_id' => $user['school_id'] ?? null,
            'subject_id' => $input['subject_id'] ?? null,
            'class_id' => $input['class_id'] ?? null,
            'term_id' => $input['term_id'] ?? null,
            'title' => $title,
            'body' => $body,
        ]);
        Response::success(Passage::findOwned($id, (int)$auth['sub']), 'Passage created', 201);
    }
}
