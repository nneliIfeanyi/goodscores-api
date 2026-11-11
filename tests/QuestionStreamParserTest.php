<?php

declare(strict_types=1);

namespace Tests;

use App\Services\QuestionStreamParser;
use PHPUnit\Framework\TestCase;

final class QuestionStreamParserTest extends TestCase
{
    public function testCountsCompleteQuestionsAcrossChunksAndIgnoresQuotedKeys(): void
    {
        $response = json_encode([
            'passage' => [
                'title' => 'Examples',
                'body' => 'The literal "questions": [ text is not the question array.',
            ],
            'questions' => [
                [
                    'type' => 'mcq',
                    'body' => 'Count {braces}, [brackets], and "quotes".',
                    'options' => [['key' => 'A', 'text' => 'One']],
                ],
                ['type' => 'theory', 'body' => 'Second question'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        self::assertIsString($response);

        $parser = new QuestionStreamParser();
        $completed = 0;
        $progress = [];
        foreach (str_split($response, 11) as $chunk) {
            $nextCompleted = $parser->push($chunk);
            if ($nextCompleted !== $completed) {
                $completed = $nextCompleted;
                $progress[] = $completed;
            }
        }

        self::assertSame([1, 2], $progress);
        self::assertSame(2, $parser->push(''));
    }

    public function testDoesNotCountAnIncompleteQuestion(): void
    {
        $parser = new QuestionStreamParser();

        self::assertSame(0, $parser->push('{"questions":[{"body":"An unfinished'));
        self::assertSame(1, $parser->push(' question"}]}'));
    }
}
