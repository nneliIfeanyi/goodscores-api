<?php

declare(strict_types=1);

namespace Tests;

use App\Services\AiQuestionService;
use PHPUnit\Framework\TestCase;

final class AiQuestionServiceTest extends TestCase
{
    public function testValidQuestionsAreNormalizedAndDiagramInstructionsArePreserved(): void
    {
        $result = AiQuestionService::validateQuestions([
            [
                'type' => 'theory',
                'body' => '  Draw a leaf. ',
                'options' => null,
                'answer' => 'A labelled leaf',
                'marks' => 2,
                'difficulty' => 'easy',
                'topic' => 'Plants',
                'diagram_request' => [
                    'description' => 'A simple labelled leaf diagram',
                    'labels' => ['blade', 'stem'],
                ],
            ],
        ], 1);

        self::assertTrue($result['success']);
        self::assertSame('Draw a leaf.', $result['questions'][0]['body']);
        self::assertSame('A simple labelled leaf diagram', $result['questions'][0]['diagram_request']['description']);
    }

    public function testWrongQuestionCountFails(): void
    {
        $result = AiQuestionService::validateQuestions([], 1);

        self::assertFalse($result['success']);
        self::assertSame(502, $result['status']);
    }

    public function testInvalidQuestionTypeFails(): void
    {
        $result = AiQuestionService::validateQuestions([
            ['type' => 'essay', 'body' => 'Question'],
        ], 1);

        self::assertFalse($result['success']);
        self::assertStringContainsString('invalid question', strtolower($result['error']));
    }

    public function testMcqNeedsAtLeastTwoOptions(): void
    {
        $result = AiQuestionService::validateQuestions([
            ['type' => 'mcq', 'body' => 'Choose one', 'options' => [['key' => 'A', 'text' => 'Only one']]],
        ], 1);

        self::assertFalse($result['success']);
        self::assertStringContainsString('without enough options', $result['error']);
    }

    public function testUnsafeOrEmptyDiagramRequestIsDiscarded(): void
    {
        $result = AiQuestionService::validateQuestions([
            [
                'type' => 'theory',
                'body' => 'Draw a circle',
                'diagram_request' => ['description' => '  ', 'labels' => ['x']],
            ],
        ], 1);

        self::assertTrue($result['success']);
        self::assertNull($result['questions'][0]['diagram_request']);
    }
}
