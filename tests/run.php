<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/AiQuestionService.php';
require_once __DIR__ . '/../src/Controllers/AiController.php';
require_once __DIR__ . '/../src/Controllers/CreditController.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$valid = App\Services\AiQuestionService::validateQuestions([
    [
        'type' => 'theory',
        'body' => ' Draw a leaf. ',
        'diagram_request' => [
            'description' => 'A labelled leaf',
            'labels' => ['blade', 'stem'],
            'svg' => '<svg viewBox="0 0 20 20"><script>alert(1)</script><circle cx="10" cy="10" r="8" /></svg>',
        ],
    ],
], 1);
check($valid['success'] === true, 'Valid diagram question should pass');
check($valid['questions'][0]['body'] === 'Draw a leaf.', 'Question body should be trimmed');
check($valid['questions'][0]['diagram_request']['description'] === 'A labelled leaf', 'Diagram request should be preserved');
check(!str_contains($valid['questions'][0]['diagram_request']['svg'], '<script>'), 'Unsafe SVG script should be removed');

$count = App\Services\AiQuestionService::validateQuestions([], 1);
check($count['success'] === false, 'Wrong question count should fail');

$mcq = App\Services\AiQuestionService::validateQuestions([
    ['type' => 'mcq', 'body' => 'Choose one', 'options' => [['key' => 'A', 'text' => 'Only one']]],
], 1);
check($mcq['success'] === false, 'MCQ with one option should fail');

$invalidDiagram = App\Services\AiQuestionService::validateQuestions([
    ['type' => 'theory', 'body' => 'Draw a circle', 'diagram_request' => ['description' => ' ', 'labels' => ['x']]],
], 1);
check($invalidDiagram['questions'][0]['diagram_request'] === null, 'Empty diagram request should be discarded');

check(App\Controllers\AiController::DAILY_REQUEST_LIMIT === 10, 'Daily AI limit should be 10');
check(App\Controllers\AiController::MAX_QUESTIONS_PER_REQUEST === 10, 'Per-request AI limit should be 10');

check(App\Controllers\CreditController::canPurchaseCredits(['school_id' => null, 'role' => 'individual']) === true, 'Individual users should be able to purchase credits');
check(App\Controllers\CreditController::canPurchaseCredits(['school_id' => 7, 'role' => 'school_admin']) === true, 'School admins should be able to purchase school credits');
check(App\Controllers\CreditController::canPurchaseCredits(['school_id' => 7, 'role' => 'teacher']) === false, 'School teachers should not be able to purchase credits');

fwrite(STDOUT, "AI contract tests passed\n");
