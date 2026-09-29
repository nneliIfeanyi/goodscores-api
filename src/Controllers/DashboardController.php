<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Middleware\Auth;

class DashboardController
{
    public function stats(): void
    {
        $auth = Auth::requireAuth();
        $db = Database::getInstance();
        $userId = (int)$auth['sub'];

        $questions = $db->prepare('SELECT COUNT(*) FROM questions WHERE user_id = ? AND is_deleted = 0');
        $questions->execute([$userId]);
        $papers = $db->prepare('SELECT COUNT(*) FROM exam_papers WHERE user_id = ?');
        $papers->execute([$userId]);

        Response::success([
            'question_count' => (int)$questions->fetchColumn(),
            'paper_count' => (int)$papers->fetchColumn(),
        ]);
    }
}
