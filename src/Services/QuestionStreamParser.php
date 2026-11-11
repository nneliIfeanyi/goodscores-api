<?php

declare(strict_types=1);

namespace App\Services;

final class QuestionStreamParser
{
    private string $prefix = '';
    private bool $insideQuestions = false;
    private bool $insideString = false;
    private bool $escaped = false;
    private bool $questionsClosed = false;
    private int $itemDepth = 0;
    private int $completed = 0;
    private string $currentQuestion = '';

    public function push(string $delta): int
    {
        if ($this->questionsClosed || $delta === '') {
            return $this->completed;
        }

        if (!$this->insideQuestions) {
            $this->prefix .= $delta;
            if (preg_match('/(?<!\\\\)"questions"\s*:\s*\[/s', $this->prefix, $match, PREG_OFFSET_CAPTURE) !== 1) {
                return $this->completed;
            }

            $afterArrayStart = $match[0][1] + strlen($match[0][0]);
            $remaining = substr($this->prefix, $afterArrayStart);
            $this->prefix = '';
            $this->insideQuestions = true;

            return $this->scanQuestionItems($remaining);
        }

        return $this->scanQuestionItems($delta);
    }

    private function scanQuestionItems(string $text): int
    {
        $length = strlen($text);
        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];

            if ($this->itemDepth === 0) {
                if ($character === ']') {
                    $this->questionsClosed = true;
                    return $this->completed;
                }
                if ($character === '{') {
                    $this->itemDepth = 1;
                    $this->insideString = false;
                    $this->escaped = false;
                    $this->currentQuestion = '{';
                }
                continue;
            }

            $this->currentQuestion .= $character;
            if ($this->insideString) {
                if ($this->escaped) {
                    $this->escaped = false;
                } elseif ($character === '\\') {
                    $this->escaped = true;
                } elseif ($character === '"') {
                    $this->insideString = false;
                }
                continue;
            }

            if ($character === '"') {
                $this->insideString = true;
            } elseif ($character === '{' || $character === '[') {
                $this->itemDepth++;
            } elseif ($character === '}' || $character === ']') {
                $this->itemDepth--;
                if ($this->itemDepth === 0) {
                    $question = json_decode($this->currentQuestion, true);
                    if (is_array($question) && json_last_error() === JSON_ERROR_NONE) {
                        $this->completed++;
                    }
                    $this->currentQuestion = '';
                }
            }
        }

        return $this->completed;
    }
}
