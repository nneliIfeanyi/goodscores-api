<?php

namespace App\Services;

/**
 * Image text extraction through OpenAI vision.
 * Set OPENAI_API_KEY in .env; the key never reaches the browser.
 */
class OcrService
{
    public static function extractText(string $imageBase64OrDataUrl): array
    {
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        if ($apiKey === '') {
            return [
                'success' => false,
                'error'   => 'OCR not configured. Set OPENAI_API_KEY in backend/.env',
            ];
        }

        // Strip data-URL prefix if present
        $raw = $imageBase64OrDataUrl;
        if (preg_match('/^data:image\/[a-zA-Z0-9+.-]+;base64,/', $raw)) {
            $raw = substr($raw, strpos($raw, ',') + 1);
        }
        $raw = preg_replace('/\s+/', '', $raw);

        $bin = base64_decode($raw, true);
        if ($bin === false || strlen($bin) < 100) {
            return ['success' => false, 'error' => 'Invalid image data'];
        }
        if (strlen($bin) > 10 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Image too large for OCR (max 10MB)'];
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bin) ?: 'image/jpeg';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return ['success' => false, 'error' => 'OCR supports JPG, PNG, GIF, and WebP images only'];
        }
        $model = $_ENV['OPENAI_VISION_MODEL'] ?? ($_ENV['OPENAI_MODEL'] ?? 'gpt-4o-mini');
        $payload = [
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 2000,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Transcribe all readable text in this image exactly. Preserve question numbering and option letters. Return only the transcription, with no commentary.'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bin), 'detail' => 'high']],
                ],
            ]],
        ];

        $url = 'https://api.openai.com/v1/chat/completions';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => 'OCR request failed: ' . $curlErr];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? ('OpenAI vision error HTTP ' . $httpCode);
            return ['success' => false, 'error' => $msg];
        }

        $annotation = $data['choices'][0]['message']['content'] ?? '';
        if (is_array($annotation)) {
            $annotation = implode('', array_map(fn($part) => (string)($part['text'] ?? ''), $annotation));
        }
        $annotation = trim((string)$annotation);
        $signal = preg_replace('/[^\p{L}\p{N}]+/u', '', $annotation);
        if ($annotation === '' || mb_strlen($signal) < 8) {
            return ['success' => false, 'error' => 'No usable question text found. Try a clearer image.'];
        }

        return [
            'success' => true,
            'text'    => trim($annotation),
            'raw'     => $data['choices'][0] ?? null,
        ];
    }

    /**
     * Heuristic: try to parse MCQ-style OCR text into body + options.
     */
    public static function parseIntoQuestion(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));

        $options = [];
        $bodyLines = [];
        $optionPattern = '/^([A-Ea-e]|[1-5])[\.\)\-:\s]+(.+)$/';

        foreach ($lines as $line) {
            if (preg_match($optionPattern, $line, $m)) {
                $key = strtoupper($m[1]);
                if (is_numeric($key)) $key = chr(64 + (int)$key);
                $options[] = [
                    'key'  => $key,
                    'text' => trim($m[2]),
                ];
            } elseif ($options) {
                $options[array_key_last($options)]['text'] .= ' ' . $line;
            } else {
                $bodyLines[] = $line;
            }
        }

        $body = implode("\n", $bodyLines);
        $type = count($options) >= 2 ? 'mcq' : 'theory';

        return [
            'type'    => $type,
            'body'    => $body ?: $text,
            'options' => $options ?: null,
            'answer'  => null,
        ];
    }

    /** Parse numbered questions from a multi-question OCR result. */
    public static function parseIntoQuestions(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
        $chunks = [];
        $current = [];
        $started = false;

        foreach ($lines as $line) {
            if (preg_match('/^\d+[\.)]\s+/', $line)) {
                if ($started && $current) $chunks[] = $current;
                $current = [preg_replace('/^\d+[\.)]\s+/', '', $line)];
                $started = true;
            } elseif ($current) {
                $current[] = $line;
            }
        }
        if ($started && $current) $chunks[] = $current;
        if (!$started) $current = $lines;

        $questions = [];
        foreach ($chunks as $chunk) {
            $question = self::parseIntoQuestion(implode("\n", $chunk));
            if (trim($question['body'] ?? '') !== '') $questions[] = $question;
        }

        return $questions ?: [self::parseIntoQuestion($text)];
    }
}
