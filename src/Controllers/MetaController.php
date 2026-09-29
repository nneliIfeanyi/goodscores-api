<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\Auth;
use App\Models\Meta;

class MetaController
{
    private function schoolIdFromAuth(): ?int
    {
        $auth = Auth::requireAuth();
        $sid = $auth['school_id'] ?? null;
        if ($sid === null || $sid === '' || $sid === 0 || $sid === '0') {
            return null;
        }
        return (int) $sid;
    }

    public function subjects(): void
    {
        $auth = Auth::requireAuth();
        $schoolId = $this->schoolIdFromAuth();
        Response::success(Meta::subjects($schoolId, (int)$auth['sub']));
    }

    public function classes(): void
    {
        $auth = Auth::requireAuth();
        $schoolId = $this->schoolIdFromAuth();
        Response::success(Meta::classes($schoolId, (int)$auth['sub']));
    }

    public function createSubject(): void
    {
        $auth = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') Response::error('Subject name is required');
        $id = Meta::createSubject((int)$auth['sub'], $this->schoolIdFromAuth(), $name, $input['code'] ?? null);
        Response::success(['id' => $id, 'name' => $name, 'code' => $input['code'] ?? null], 'Subject created', 201);
    }

    public function updateSubject(int $id): void
    {
        $auth = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') Response::error('Subject name is required');
        if (!Meta::updateSubject($id, (int)$auth['sub'], $name, $input['code'] ?? null)) Response::error('Subject not found', 404);
        Response::success(null, 'Subject updated');
    }

    public function deleteSubject(int $id): void
    {
        $auth = Auth::requireAuth();
        if (!Meta::deleteSubject($id, (int)$auth['sub'])) Response::error('Subject not found', 404);
        Response::success(null, 'Subject deleted');
    }

    public function createClass(): void
    {
        $auth = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') Response::error('Class name is required');
        $id = Meta::createClass((int)$auth['sub'], $this->schoolIdFromAuth(), $name, (int)($input['sort_order'] ?? 0));
        Response::success(['id' => $id, 'name' => $name], 'Class created', 201);
    }

    public function updateClass(int $id): void
    {
        $auth = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') Response::error('Class name is required');
        if (!Meta::updateClass($id, (int)$auth['sub'], $name, (int)($input['sort_order'] ?? 0))) Response::error('Class not found', 404);
        Response::success(null, 'Class updated');
    }

    public function deleteClass(int $id): void
    {
        $auth = Auth::requireAuth();
        if (!Meta::deleteClass($id, (int)$auth['sub'])) Response::error('Class not found', 404);
        Response::success(null, 'Class deleted');
    }

    public function terms(): void
    {
        $schoolId = $this->schoolIdFromAuth();
        Meta::ensureDefaults();
        Response::success(Meta::terms($schoolId));
    }

    public function topics(): void
    {
        $auth = Auth::requireAuth();
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        if (!$subjectId) {
            Response::error('subject_id required');
        }
        if (!Meta::subjectBelongsToTeacher($subjectId, (int)$auth['sub'])) {
            Response::error('Subject not found', 404);
        }
        Response::success(Meta::topics($subjectId));
    }

    public function createTopic(): void
    {
        $auth = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $subjectId = (int)($input['subject_id'] ?? 0);
        $name = trim($input['name'] ?? '');
        if (!$subjectId || !$name) {
            Response::error('subject_id and name required');
        }
        if (!Meta::subjectBelongsToTeacher($subjectId, (int)$auth['sub'])) {
            Response::error('Subject not found', 404);
        }
        $id = Meta::createTopic($subjectId, $name);
        Response::success(['id' => $id, 'name' => $name], 'Topic created', 201);
    }
}
