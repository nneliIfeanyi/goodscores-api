<?php

namespace App\Services;

/**
 * Generates optional educational illustrations and stores processed WebP files.
 * The OpenAI key and raw image response remain server-side.
 */
class AiImageService
{
    public static function generateAndStore(array $question, array $spec, int $questionId): array
    {
        $rawResult = self::generateRaw($spec);
        if (!$rawResult['success']) {
            return $rawResult;
        }
        $raw = $rawResult['raw'];
        $processed = self::processImage($raw);
        if (!$processed['success']) return $processed;

        $directory = __DIR__ . '/../../storage/uploads/questions/' . $questionId;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return ['success' => false, 'status' => 500, 'error' => 'Could not create image storage directory'];
        }
        $filename = 'ai_illustration_' . bin2hex(random_bytes(12)) . '.webp';
        $fullPath = $directory . '/' . $filename;
        if (file_put_contents($fullPath, $processed['data']) === false) {
            return ['success' => false, 'status' => 500, 'error' => 'Could not save generated illustration'];
        }

        $relative = 'uploads/questions/' . $questionId . '/' . $filename;
        $imageId = \App\Models\Question::addImage($questionId, [
            'file_path' => $relative,
            'original_name' => $filename,
            'mime_type' => 'image/webp',
            'file_size' => strlen($processed['data']),
            'type' => 'ai_illustration',
            'position' => 'after_body',
            'caption' => $spec['description'],
            'sort_order' => 0,
        ]);

        return [
            'success' => true,
            'image' => [
                'id' => $imageId,
                'file_path' => $relative,
                'url' => '/storage/' . $relative,
                'mime_type' => 'image/webp',
            ],
        ];
    }

    public static function generatePreview(array $spec): array
    {
        $rawResult = self::generateRaw($spec);
        if (!$rawResult['success']) {
            return $rawResult;
        }
        $processed = self::processImage($rawResult['raw']);
        if (!$processed['success']) {
            return $processed;
        }

        return [
            'success' => true,
            'mime_type' => 'image/webp',
            'data_url' => 'data:image/webp;base64,' . base64_encode($processed['data']),
        ];
    }

    private static function generateRaw(array $spec): array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return ['success' => false, 'status' => 503, 'error' => 'Server image processing is not available. Enable the PHP GD extension.'];
        }

        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        if ($apiKey === '') {
            return ['success' => false, 'status' => 503, 'error' => 'Image generation is not configured.'];
        }

        $prompt = trim((string)($spec['image_prompt'] ?? $spec['description'] ?? ''));
        if ($prompt === '') {
            return ['success' => false, 'status' => 422, 'error' => 'An illustration prompt is required.'];
        }
        $audienceProfile = trim((string)($spec['audience_profile'] ?? ''));
        $audienceInstruction = match ($audienceProfile) {
            'early_learners' => 'Keep shapes simple and friendly, avoid clutter, and use warm child-safe classroom visuals for ages 5-8.',
            'upper_primary' => 'Use clear educational visuals for ages 9-11 with moderate detail and easy recognition.',
            'junior_secondary' => 'Use accurate but approachable school-level detail for junior secondary learners.',
            'senior_secondary' => 'Use higher academic detail suitable for senior secondary learners while staying clean and readable.',
            default => 'Use a balanced classroom-appropriate detail level for mixed learners.',
        };
        $prompt = 'Create a clear, age-appropriate educational illustration for a school question. '
            . 'Do not include labels, written answers, mathematical notation, measurements, graphs, or exact geometry. '
            . 'Use a clean light background and make the requested subject easy for learners to recognize. '
            . $audienceInstruction . ' '
            . substr($prompt, 0, 1200);

        $payload = [
            'model' => 'gpt-image-2',
            'prompt' => $prompt,
            'size' => '1024x1024',
            'quality' => 'low',
            'output_format' => 'png',
        ];
        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'status' => 502, 'error' => 'Could not connect to the image service: ' . $curlError];
        }
        $data = json_decode($response, true);
        if ($httpCode >= 400) {
            return ['success' => false, 'status' => 502, 'error' => $data['error']['message'] ?? 'Image generation failed'];
        }
        $encoded = $data['data'][0]['b64_json'] ?? '';
        $raw = base64_decode($encoded, true);
        if ($raw === false || $raw === '') {
            return ['success' => false, 'status' => 502, 'error' => 'Image service returned no usable image'];
        }
        return ['success' => true, 'raw' => $raw];
    }

    private static function processImage(string $raw): array
    {
        $source = @imagecreatefromstring($raw);
        if (!$source) return ['success' => false, 'status' => 502, 'error' => 'Generated image could not be decoded'];
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, 480 / max($width, $height));
        $targetWidth = max(1, (int)round($width * $scale));
        $targetHeight = max(1, (int)round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagefilter($target, IMG_FILTER_GRAYSCALE);
        ob_start();
        imagewebp($target, null, 78);
        $webp = ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);
        return $webp === false || $webp === ''
            ? ['success' => false, 'status' => 500, 'error' => 'Could not compress generated image']
            : ['success' => true, 'data' => $webp];
    }
}
