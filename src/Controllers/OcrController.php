<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\Auth;
use App\Models\User;
use App\Services\CreditService;
use App\Services\OcrService;

class OcrController
{
    public function extract(): void
    {
        $auth = Auth::requireAuth();
        \App\Middleware\RateLimit::attemptAiOcrBurst((string)$auth['sub']);
        \App\Middleware\RateLimit::attempt('ocr', 10, 600, (string)$auth['sub']);

        $user = User::findById((int)$auth['sub']);
        if (!$user) {
            Response::error('User not found', 404);
        }

        $cost = (int)($_ENV['OCR_CREDIT_COST'] ?? CreditService::OCR_COST);

        if (!CreditService::canAffordFor($user, 'ocr', $cost)) {
            Response::error('Insufficient credits for OCR (need ' . $cost . ')', 402);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $image = $input['image'] ?? '';
        if ($image === '') {
            Response::error('image (base64) is required');
        }

        $result = OcrService::extractText($image);
        if (!$result['success']) {
            Response::error($result['error'] ?? 'OCR failed', 502);
        }

        // Deduct only after successful OCR
        $deduct = CreditService::deductFor($user, 'ocr', $cost, 'OCR text extraction', 'ocr');
        if (!$deduct['success']) {
            Response::error($deduct['message'] ?? 'Credit deduction failed', 402);
        }

        $parsedQuestions = OcrService::parseIntoQuestions($result['text']);

        // Refresh credit balance
        $user = User::findById((int)$auth['sub']);
        $credits = CreditService::getUserEffectiveCredits($user);

        \App\Services\UsageService::log((int)$auth['sub'], 'ocr', [
            'chars' => strlen($result['text']),
            'cost'  => $cost,
        ]);

        Response::success([
            'text'         => $result['text'],
            'parsed'       => $parsedQuestions[0] ?? null,
            'questions'    => $parsedQuestions,
            'credits_left' => $credits['balance'],
            'cost'         => $cost,
        ], 'OCR complete');
    }
}

