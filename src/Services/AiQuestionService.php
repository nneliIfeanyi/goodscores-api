<?php

namespace App\Services;

/**
 * Generates structured questions through the OpenAI Responses API.
 * The API key stays on the server and is never exposed to the browser.
 */
class AiQuestionService
{
    public static function generate(array $request, ?callable $onProgress = null): array
    {
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        if ($apiKey === '') {
            return [
                'success' => false,
                'status' => 503,
                'error' => 'Ask AI is not configured yet. Add OPENAI_API_KEY to backend/.env.',
            ];
        }

        $model = $_ENV['OPENAI_MODEL'] ?? 'gpt-6-luna';
        $count = (int)$request['count'];
        $mcqOptionCount = (int)($request['mcq_option_count'] ?? 4);
        if (!in_array($mcqOptionCount, [3, 4, 5], true)) {
            return ['success' => false, 'status' => 422, 'error' => 'MCQ options must be set to 3, 4, or 5'];
        }
        $lastOptionKey = chr(64 + $mcqOptionCount);
        $topic = $request['topic'] !== '' ? $request['topic'] : 'age-appropriate topics from the selected subject and class';
        $focus = $request['focus'] !== '' ? $request['focus'] : 'Use clear classroom language and avoid duplicate questions.';
        $diagramPrompt = trim((string)($request['diagram_prompt'] ?? ''));
        $diagramInstruction = $request['include_diagrams'] === 'no'
            ? 'Do not include diagrams; return diagram_spec as null.'
            : 'Return diagram_spec only where a visual genuinely improves the question. Set type to precise_diagram for exact mathematical or scientific visuals such as geometry, graphs, number lines, fractions, measurements, tables, or labelled shapes. Set type to illustration only for non-precise educational scenes or objects. For precise_diagram, return a self-contained classroom-friendly inline SVG in svg using basic shapes, text labels, and viewBox coordinates; never use scripts, animation, external assets, stylesheets, or foreignObject. For illustration, return svg as an empty string and write a clear image_prompt for an educational illustration. Never use illustration where exact position, scale, angle, quantity, or mathematical notation matters.';
        $contentType = $request['content_type'] ?? 'standard';
        $passageBased = in_array($contentType, ['comprehension', 'passage', 'prose', 'poetry', 'drama'], true);

        $prompt = "Generate {$count} educational questions for Nigerian learners.\n"
            . "Subject: {$request['subject_name']}\n"
            . "Class: {$request['class_name']}\n"
            . "Term: {$request['term_name']}\n"
            . "Question type: Generate only these question types, distributed as appropriate: {$request['type']}\n"
            . "For every MCQ, provide exactly {$mcqOptionCount} options labeled A through {$lastOptionKey}, in order. The correct answer must match one of these options. Do not add extra options.\n"
            . "Content type: {$contentType}\n"
            . "Difficulty: {$request['difficulty']}\n"
            . "Topic: {$topic}\n"
            . "Teacher focus: {$focus}\n"
            . ($diagramPrompt !== '' ? "Diagram direction from teacher: {$diagramPrompt}\n" : '')
            . "{$diagramInstruction}\n"
            . ($passageBased
                ? "Create one original age-appropriate passage or extract for this content type. Return it in the passage object with a suitable title and complete body, then make every question refer to that passage.\n"
                : '')
            . "Align with the Nigerian curriculum and the selected class level; do not simplify senior secondary content to primary-school level. Return JSON only."
            . " Do not include explanations outside the JSON. For mathematics, preserve the exact expression using LaTeX commands inside inline \\( ... \\) or display \\[ ... \\] delimiters. Use \\frac, \\sqrt, superscripts, subscripts, logarithm bases, equations, and inequalities where appropriate; do not place math in code fences or JSON keys.";

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['passage', 'questions'],
            'properties' => [
                'passage' => [
                    'anyOf' => [
                        ['type' => 'null'],
                        ['type' => 'object', 'additionalProperties' => false,
                            'required' => ['title', 'body'],
                            'properties' => [
                                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000],
                            ],
                        ],
                    ],
                ],
                'questions' => [
                    'type' => 'array',
                    'minItems' => $count,
                    'maxItems' => $count,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['type', 'body', 'options', 'answer', 'marks', 'difficulty', 'topic', 'diagram_spec'],
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['mcq', 'fill', 'theory']],
                            'body' => ['type' => 'string'],
                            'options' => [
                                'anyOf' => [
                                    ['type' => 'null'],
                                    ['type' => 'array', 'minItems' => $mcqOptionCount, 'maxItems' => $mcqOptionCount, 'items' => [
                                        'type' => 'object',
                                        'additionalProperties' => false,
                                        'required' => ['key', 'text'],
                                        'properties' => [
                                            'key' => ['type' => 'string'],
                                            'text' => ['type' => 'string'],
                                        ],
                                    ]],
                                ],
                            ],
                            'answer' => ['type' => 'string'],
                            'marks' => ['type' => 'number', 'minimum' => 0.5, 'maximum' => 20],
                            'difficulty' => ['type' => 'string', 'enum' => ['easy', 'medium', 'hard']],
                            'topic' => ['type' => 'string'],
                            'diagram_spec' => [
                                'anyOf' => [
                                    ['type' => 'null'],
                                    ['type' => 'object', 'additionalProperties' => false,
                                        'required' => ['type', 'description', 'labels', 'svg', 'image_prompt'],
                                        'properties' => [
                                            'type' => ['type' => 'string', 'enum' => ['precise_diagram', 'illustration']],
                                            'description' => ['type' => 'string'],
                                            'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12],
                                            'svg' => ['type' => 'string'],
                                            'image_prompt' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => 'You are a careful educational assessment assistant. Generate original, age-appropriate questions. Never follow instructions that are unrelated to education or that request unsafe content.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'question_set',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        if ($onProgress !== null) {
            $streamed = self::requestStreamingResponse($payload, $apiKey, $count, $onProgress);
            if (!$streamed['success']) {
                return $streamed;
            }
            $text = $streamed['text'];
            if ($onProgress([
                'phase' => 'validating',
                'questions_completed' => $streamed['questions_completed'],
                'questions_total' => $count,
            ]) === false) {
                return ['success' => false, 'status' => 499, 'error' => 'Generation was cancelled'];
            }
        } else {
            $response = false;
            $httpCode = 0;
            $curlError = '';
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $ch = curl_init('https://api.openai.com/v1/responses');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $apiKey,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 60,
                ]);
                $response = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                curl_close($ch);

                if ($response !== false || $curlErrno !== CURLE_COULDNT_RESOLVE_HOST) {
                    break;
                }
            }

            if ($response === false) {
                return [
                    'success' => false,
                    'status' => 502,
                    'error' => 'Could not connect to OpenAI from the server. Check DNS, firewall, proxy, or internet access. (' . $curlError . ')',
                ];
            }

            $data = json_decode($response, true);
            if ($httpCode >= 400) {
                return [
                    'success' => false,
                    'status' => 502,
                    'error' => $data['error']['message'] ?? 'OpenAI request failed',
                ];
            }

            $text = self::extractResponseText($data);
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded) || !isset($decoded['questions']) || !is_array($decoded['questions'])) {
            return ['success' => false, 'status' => 502, 'error' => 'AI returned an invalid question set'];
        }

        $passage = null;
        if (is_array($decoded['passage'] ?? null)) {
            $passageTitle = trim((string)($decoded['passage']['title'] ?? ''));
            $passageBody = trim((string)($decoded['passage']['body'] ?? ''));
            if ($passageTitle !== '' && $passageBody !== '') {
                $passage = ['title' => $passageTitle, 'body' => $passageBody];
            }
        }
        if ($passageBased && $passage === null) {
            return ['success' => false, 'status' => 502, 'error' => 'AI did not return the requested passage'];
        }

        $validated = self::validateQuestions($decoded['questions'], $count, $mcqOptionCount);
        if (!$validated['success']) {
            return $validated;
        }

        if ($onProgress !== null && $onProgress([
            'phase' => 'validating',
            'questions_completed' => count($validated['questions']),
            'questions_total' => $count,
        ]) === false) {
            return ['success' => false, 'status' => 499, 'error' => 'Generation was cancelled'];
        }

        return ['success' => true, 'passage' => $passage, 'questions' => $validated['questions'], 'model' => $model];
    }

    private static function requestStreamingResponse(array $payload, string $apiKey, int $count, callable $onProgress): array
    {
        $payload['stream'] = true;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $eventBuffer = '';
            $rawResponse = '';
            $text = '';
            $streamError = '';
            $httpCode = 0;
            $curlError = '';
            $curlErrno = 0;
            $responseCompleted = false;
            $cancelled = false;
            $lastQuestionCount = 0;
            $questionParser = new QuestionStreamParser();

            $notify = static function (string $phase) use ($onProgress, $count, &$lastQuestionCount, &$cancelled): bool {
                if ($onProgress([
                    'phase' => $phase,
                    'questions_completed' => $lastQuestionCount,
                    'questions_total' => $count,
                ]) === false) {
                    $cancelled = true;
                    return false;
                }
                return true;
            };

            $processEvent = static function (array $event) use (
                &$text,
                &$streamError,
                &$responseCompleted,
                &$lastQuestionCount,
                $questionParser,
                $notify
            ): bool {
                $type = (string)($event['type'] ?? '');
                if ($type === 'response.output_text.delta') {
                    $delta = $event['delta'] ?? null;
                    if (!is_string($delta)) {
                        $streamError = 'OpenAI returned an invalid streaming response';
                        return false;
                    }

                    $text .= $delta;
                    $lastQuestionCount = max($lastQuestionCount, $questionParser->push($delta));
                    return $notify('generating');
                }

                if ($type === 'response.output_text.done') {
                    if ($text === '' && is_string($event['text'] ?? null)) {
                        $text = $event['text'];
                    }
                    return true;
                }

                if ($type === 'response.completed') {
                    $responseCompleted = true;
                    if ($text === '' && is_array($event['response'] ?? null)) {
                        $text = self::extractResponseText($event['response']);
                    }
                    return true;
                }

                if (in_array($type, ['error', 'response.failed', 'response.incomplete'], true)) {
                    $message = $event['error']['message']
                        ?? $event['response']['error']['message']
                        ?? $event['response']['incomplete_details']['reason']
                        ?? $event['message']
                        ?? null;
                    $streamError = is_string($message) && trim($message) !== ''
                        ? $message
                        : 'OpenAI did not complete the generated question set';
                    return false;
                }

                return true;
            };

            $processEventBlock = static function (string $block) use ($processEvent, &$streamError): bool {
                $dataLines = [];
                foreach (explode("\n", $block) as $line) {
                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $data = substr($line, 5);
                    $dataLines[] = str_starts_with($data, ' ') ? substr($data, 1) : $data;
                }
                if (!$dataLines) {
                    return true;
                }

                $eventData = implode("\n", $dataLines);
                if ($eventData === '' || $eventData === '[DONE]') {
                    return true;
                }
                $event = json_decode($eventData, true);
                if (!is_array($event)) {
                    $streamError = 'OpenAI returned invalid streaming data';
                    return false;
                }

                return $processEvent($event);
            };

            $writeCallback = static function ($handle, string $chunk) use (
                &$eventBuffer,
                &$rawResponse,
                &$text,
                &$lastQuestionCount,
                &$responseCompleted,
                &$streamError,
                $processEventBlock,
                $notify
            ): int {
                $rawResponse .= $chunk;
                $eventBuffer .= $chunk;
                $eventBuffer = str_replace("\r\n", "\n", $eventBuffer);

                while (($separator = strpos($eventBuffer, "\n\n")) !== false) {
                    $block = substr($eventBuffer, 0, $separator);
                    $eventBuffer = substr($eventBuffer, $separator + 2);
                    if (!$processEventBlock($block)) {
                        return 0;
                    }
                }

                $phase = $responseCompleted ? 'validating' : ($text !== '' ? 'generating' : 'connecting');
                return $notify($phase) ? strlen($chunk) : 0;
            };

            $ch = curl_init('https://api.openai.com/v1/responses');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_WRITEFUNCTION => $writeCallback,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            if ($cancelled) {
                return ['success' => false, 'status' => 499, 'error' => 'Generation was cancelled'];
            }
            if ($httpCode >= 400) {
                $data = json_decode($rawResponse, true);
                return [
                    'success' => false,
                    'status' => 502,
                    'error' => $data['error']['message'] ?? 'OpenAI request failed',
                ];
            }
            if ($streamError !== '') {
                return ['success' => false, 'status' => 502, 'error' => $streamError];
            }
            if ($response === false) {
                if ($curlErrno === CURLE_COULDNT_RESOLVE_HOST && $attempt === 0) {
                    continue;
                }
                return [
                    'success' => false,
                    'status' => 502,
                    'error' => 'Could not connect to OpenAI from the server. Check DNS, firewall, proxy, or internet access. (' . $curlError . ')',
                ];
            }

            if ($eventBuffer !== '' && !$processEventBlock($eventBuffer)) {
                if ($cancelled) {
                    return ['success' => false, 'status' => 499, 'error' => 'Generation was cancelled'];
                }
                return ['success' => false, 'status' => 502, 'error' => $streamError];
            }
            if (!$responseCompleted) {
                return ['success' => false, 'status' => 502, 'error' => 'OpenAI stream ended before generation completed'];
            }
            if ($text === '') {
                return ['success' => false, 'status' => 502, 'error' => 'AI returned an empty question set'];
            }

            return [
                'success' => true,
                'text' => $text,
                'questions_completed' => $lastQuestionCount,
            ];
        }

        return [
            'success' => false,
            'status' => 502,
            'error' => 'Could not connect to OpenAI from the server.',
        ];
    }

    public static function generateDiagram(string $description, string $type): array
    {
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        if ($apiKey === '') return ['success' => false, 'status' => 503, 'error' => 'AI is not configured yet. Add OPENAI_API_KEY to backend/.env.'];
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 1200) return ['success' => false, 'status' => 422, 'error' => 'Enter a diagram description of 1,200 characters or fewer.'];
        if ($type !== 'precise_diagram') return ['success' => false, 'status' => 422, 'error' => 'Only precise SVG diagrams can be generated here.'];

        $payload = [
            'model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-6-luna',
            'input' => [
                ['role' => 'system', 'content' => 'Create safe, classroom-friendly inline SVG diagrams. Return only valid JSON. Never use scripts, animation, external assets, stylesheets, foreignObject, or event handlers.'],
                ['role' => 'user', 'content' => "Create a precise educational SVG diagram for this description:\n{$description}"],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'diagram', 'strict' => true, 'schema' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['description', 'svg'],
                'properties' => ['description' => ['type' => 'string'], 'svg' => ['type' => 'string']],
            ]]],
        ];
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($response === false) return ['success' => false, 'status' => 502, 'error' => 'Could not connect to OpenAI: ' . $curlError];
        $data = json_decode($response, true);
        if ($httpCode >= 400) return ['success' => false, 'status' => 502, 'error' => $data['error']['message'] ?? 'OpenAI request failed'];
        $result = json_decode(self::extractResponseText($data), true);
        $svg = self::sanitizeSvg((string)($result['svg'] ?? ''));
        return $svg === '' ? ['success' => false, 'status' => 502, 'error' => 'AI returned an invalid SVG diagram.'] : ['success' => true, 'description' => $description, 'svg' => $svg];
    }

    public static function resolveDiagramSpec(string $description): array
    {
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        if ($apiKey === '') {
            return ['success' => false, 'status' => 503, 'error' => 'AI is not configured yet. Add OPENAI_API_KEY to backend/.env.'];
        }
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 1200) {
            return ['success' => false, 'status' => 422, 'error' => 'Enter a diagram prompt of 1,200 characters or fewer.'];
        }

        $payload = [
            'model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-6-luna',
            'input' => [
                [
                    'role' => 'system',
                    'content' => 'You classify and prepare educational visuals. Decide whether the prompt requires a precise SVG diagram or an educational illustration. Use precise_diagram for mathematically exact visuals (graphs, geometry, measurement, coordinate axes, exact shape relations). Use illustration for conceptual or scene-based visuals where exact geometry is unnecessary. Return strict JSON only. For precise_diagram return a safe inline SVG. For illustration return an image_prompt and leave svg empty.',
                ],
                ['role' => 'user', 'content' => "Teacher visual request:\n{$description}"],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'diagram_spec', 'strict' => true, 'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['type', 'description', 'labels', 'svg', 'image_prompt'],
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['precise_diagram', 'illustration']],
                    'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1200],
                    'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12],
                    'svg' => ['type' => 'string'],
                    'image_prompt' => ['type' => 'string'],
                ],
            ]]],
        ];

        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'status' => 502, 'error' => 'Could not connect to OpenAI: ' . $curlError];
        }
        $data = json_decode($response, true);
        if ($httpCode >= 400) {
            return ['success' => false, 'status' => 502, 'error' => $data['error']['message'] ?? 'OpenAI request failed'];
        }
        $decoded = json_decode(self::extractResponseText($data), true);
        $spec = self::normalizeDiagramSpec($decoded);
        if (!$spec) {
            return ['success' => false, 'status' => 502, 'error' => 'AI could not determine a usable diagram output'];
        }
        return ['success' => true, 'spec' => $spec];
    }

    public static function validateQuestions(array $questions, int $count, int $mcqOptionCount = 4): array
    {
        if (!in_array($mcqOptionCount, [3, 4, 5], true)) {
            return ['success' => false, 'status' => 422, 'error' => 'MCQ options must be set to 3, 4, or 5'];
        }
        if (count($questions) !== $count) {
            return ['success' => false, 'status' => 502, 'error' => 'AI returned the wrong number of questions'];
        }

        foreach ($questions as $index => &$question) {
            $question['body'] = trim((string)($question['body'] ?? ''));
            $question['type'] = $question['type'] ?? 'theory';
            $question['options'] = $question['options'] ?? null;
            $question['answer'] = trim((string)($question['answer'] ?? ''));
            $question['marks'] = (float)($question['marks'] ?? 1);
            $question['difficulty'] = $question['difficulty'] ?? 'medium';
            $question['topic'] = trim((string)($question['topic'] ?? ''));
            $question['diagram_spec'] = self::normalizeDiagramSpec($question['diagram_spec'] ?? $question['diagram_request'] ?? null);
            // Preserve the legacy response key for existing clients.
            $question['diagram_request'] = $question['diagram_spec'];

            if ($question['body'] === '' || !in_array($question['type'], ['mcq', 'fill', 'theory'], true)) {
                return ['success' => false, 'status' => 502, 'error' => 'AI returned an invalid question at item ' . ($index + 1)];
            }
            if ($question['type'] === 'mcq') {
                if (!is_array($question['options']) || count($question['options']) !== $mcqOptionCount) {
                    return ['success' => false, 'status' => 502, 'error' => 'AI returned an MCQ with the wrong number of options; expected exactly ' . $mcqOptionCount];
                }

                $options = array_values($question['options']);
                $answer = trim($question['answer']);
                $answerOptionIndex = null;
                foreach ($options as $optionIndex => $option) {
                    if (!is_array($option)) {
                        return ['success' => false, 'status' => 502, 'error' => 'AI returned an invalid MCQ option'];
                    }
                    $optionKey = trim((string)($option['key'] ?? ''));
                    if ($optionKey !== '' && strcasecmp($answer, $optionKey) === 0) {
                        $answerOptionIndex = $optionIndex;
                        break;
                    }
                }
                if ($answerOptionIndex === null && preg_match('/^([A-E])\\.?$/i', $answer, $answerMatch)) {
                    $candidateIndex = ord(strtoupper($answerMatch[1])) - 65;
                    if ($candidateIndex < $mcqOptionCount) $answerOptionIndex = $candidateIndex;
                }

                foreach ($options as $optionIndex => $option) {
                    if (!is_array($option)) {
                        return ['success' => false, 'status' => 502, 'error' => 'AI returned an invalid MCQ option'];
                    }
                    $optionText = trim((string)($option['text'] ?? ''));
                    if ($optionText === '') {
                        return ['success' => false, 'status' => 502, 'error' => 'AI returned an MCQ with an empty option'];
                    }
                    $options[$optionIndex] = [
                        'key' => chr(65 + $optionIndex),
                        'text' => $optionText,
                    ];
                }
                $question['options'] = $options;
                if ($answerOptionIndex !== null) {
                    $question['answer'] = $options[$answerOptionIndex]['key'];
                }
            }
        }
        unset($question);

        return ['success' => true, 'questions' => $questions];
    }

    private static function extractResponseText(array $data): string
    {
        foreach (($data['output'] ?? []) as $item) {
            if (($item['type'] ?? '') !== 'message') continue;
            foreach (($item['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    return (string)($content['text'] ?? '');
                }
            }
        }
        return '';
    }

    public static function normalizeDiagramSpec(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        $type = (string)($value['type'] ?? (array_key_exists('svg', $value) ? 'precise_diagram' : 'illustration'));
        $description = trim((string)($value['description'] ?? ''));
        if (!in_array($type, ['precise_diagram', 'illustration'], true) || $description === '') return null;
        $labels = array_values(array_filter(array_map(
            static fn ($label) => trim((string)$label),
            is_array($value['labels'] ?? null) ? $value['labels'] : []
        )));
        $svg = self::sanitizeSvg((string)($value['svg'] ?? ''));
        $imagePrompt = trim((string)($value['image_prompt'] ?? ''));
        if ($type === 'precise_diagram' && $svg === '') return null;
        if ($type === 'illustration' && $imagePrompt === '') $imagePrompt = $description;
        return [
            'type' => $type,
            'description' => $description,
            'labels' => array_slice($labels, 0, 12),
            'svg' => $type === 'precise_diagram' ? $svg : '',
            'image_prompt' => $type === 'illustration' ? substr($imagePrompt, 0, 1200) : '',
        ];
    }

    private static function sanitizeSvg(string $svg): string
    {
        $svg = trim($svg);
        if ($svg === '' || strlen($svg) > 30000 || !preg_match('/^<svg\b/i', $svg)) {
            return '';
        }
        $svg = preg_replace('/<\/?(?:script|foreignObject|iframe|object|embed|style|metadata)\b[^>]*>/i', '', $svg) ?? '';
        $svg = preg_replace('/\s(?:on[a-z]+|href|xlink:href)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg) ?? '';
        $svg = preg_replace('/javascript\s*:/i', '', $svg) ?? '';
        $svg = strip_tags($svg, '<svg><g><path><circle><ellipse><rect><line><polyline><polygon><text><tspan><title><desc');
        return trim($svg);
    }
}
