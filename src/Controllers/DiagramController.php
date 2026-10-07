<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\Auth;
use App\Models\Diagram;
use App\Models\User;
use App\Services\AiImageService;
use App\Services\AiQuestionService;
use App\Services\CreditService;

class DiagramController
{
    public function index(): void
    {
        $auth = Auth::requireAuth();
        Response::success(Diagram::listByUser((int)$auth['sub']));
    }

    public function store(): void
    {
        $auth = Auth::requireAuth();
        $userId = (int)$auth['sub'];
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $source = trim((string)($input['source'] ?? 'ai'));
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            $title = 'Diagram';
        }

        if ($source === 'upload') {
            $upload = $this->saveUploadedImage((string)($input['image_data_url'] ?? ''), $userId);
            $description = trim((string)($input['description'] ?? 'Uploaded diagram'));
            $diagramId = Diagram::create([
                'user_id' => $userId,
                'title' => $title,
                'description' => $description,
                'mode' => 'illustration',
                'source' => 'upload',
                'diagram_spec' => [
                    'type' => 'illustration',
                    'description' => $description,
                    'labels' => [],
                    'svg' => '',
                    'image_prompt' => $description,
                ],
                'image_path' => $upload['relative'],
                'mime_type' => $upload['mime_type'],
                'file_size' => $upload['file_size'],
            ]);
            $created = Diagram::findById($diagramId, $userId);
            Response::success($created, 'Diagram uploaded', 201);
        }

        $result = $this->generateOrRegenerate($userId, $title, $input, null);
        Response::success($result, 'Diagram created', 201);
    }

    public function update(int $id): void
    {
        $auth = Auth::requireAuth();
        $userId = (int)$auth['sub'];
        $diagram = Diagram::findById($id, $userId);
        if (!$diagram) {
            Response::error('Diagram not found', 404);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $title = trim((string)($input['title'] ?? ($diagram['title'] ?? 'Diagram')));
        $description = trim((string)($input['description'] ?? ($diagram['description'] ?? '')));
        if ($title === '') {
            Response::error('Title is required');
        }

        if (!empty($input['regenerate'])) {
            $input['description'] = $description;
            $result = $this->generateOrRegenerate($userId, $title, $input, $diagram);
            Response::success($result, 'Diagram regenerated');
        }

        Diagram::updateMeta($id, $userId, $title, $description === '' ? null : $description);
        Response::success(Diagram::findById($id, $userId), 'Diagram updated');
    }

    public function destroy(int $id): void
    {
        $auth = Auth::requireAuth();
        $ok = Diagram::softDelete($id, (int)$auth['sub']);
        if (!$ok) {
            Response::error('Diagram not found', 404);
        }
        Response::success(null, 'Diagram deleted');
    }

    private function structuredContext(array $input): string
    {
        $parts = [];
        $learner = trim((string)($input['learner_level'] ?? ''));
        $labels = $input['required_labels'] ?? [];
        $orientation = trim((string)($input['orientation'] ?? ''));
        $exclude = trim((string)($input['exclude'] ?? ''));
        $audienceProfile = trim((string)($input['audience_profile'] ?? ''));

        if ($learner !== '') $parts[] = 'Learner/class level: ' . $learner;
        if ($audienceProfile !== '') {
            $parts[] = 'Audience profile: ' . $audienceProfile;
            if ($audienceProfile === 'early_learners') {
                $parts[] = 'Use friendly child-safe visuals, simple composition, and bright but calm colors suitable for ages 5-8.';
            }
        }
        if (is_array($labels) && $labels) {
            $clean = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $labels)));
            if ($clean) $parts[] = 'Required labels: ' . implode(', ', $clean);
        }
        if ($orientation !== '') $parts[] = 'Orientation/layout: ' . $orientation;
        if ($exclude !== '') $parts[] = 'What must not appear: ' . $exclude;

        return implode("\n", $parts);
    }

    private function requestedContext(array $input): array
    {
        $labels = $input['required_labels'] ?? [];
        if (!is_array($labels)) {
            $labels = [];
        }
        $cleanLabels = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $labels)));
        return [
            'audience_profile' => trim((string)($input['audience_profile'] ?? '')),
            'learner_level' => trim((string)($input['learner_level'] ?? '')),
            'required_labels' => $cleanLabels,
            'orientation' => trim((string)($input['orientation'] ?? '')),
            'exclude' => trim((string)($input['exclude'] ?? '')),
        ];
    }

    private function generateOrRegenerate(int $userId, string $title, array $input, ?array $existing): array
    {
        $description = trim((string)($input['description'] ?? ''));
        if ($description === '') {
            Response::error('Diagram prompt is required');
        }

        $context = $this->requestedContext($input);
        $structured = $this->structuredContext($context);
        $requestText = $structured === '' ? $description : $description . "\n\n" . $structured;

        $resolved = AiQuestionService::resolveDiagramSpec($requestText);
        if (!$resolved['success']) {
            Response::error($resolved['error'] ?? 'Could not resolve diagram type', $resolved['status'] ?? 502);
        }
        $spec = $resolved['spec'];
        $spec['requested_context'] = $context;
        if ($context['audience_profile'] !== '') {
            $spec['audience_profile'] = $context['audience_profile'];
        }

        $user = User::findById($userId);
        if (!$user) {
            Response::error('User not found', 404);
        }

        if (($spec['type'] ?? '') === 'precise_diagram' && ($spec['svg'] ?? '') === '') {
            $svg = AiQuestionService::generateDiagram($spec['description'] ?? $requestText, 'precise_diagram');
            if (!$svg['success']) {
                Response::error($svg['error'] ?? 'Could not generate SVG', $svg['status'] ?? 502);
            }
            $spec['svg'] = $svg['svg'];
        }

        if (($spec['type'] ?? '') === 'illustration') {
            $cost = (int)($_ENV['AI_IMAGE_CREDIT_COST'] ?? 50);
            if (!CreditService::canAffordFor($user, 'ai', $cost)) {
                Response::error('Insufficient credits for illustration generation (need ' . $cost . ')', 402);
            }
            $usageToken = \App\Middleware\RateLimit::reserveDaily('diagram_illustration', (string)$userId, 5);
            $preview = AiImageService::generatePreview($spec);
            if (!$preview['success']) {
                \App\Middleware\RateLimit::release('diagram_illustration', (string)$userId, $usageToken);
                Response::error($preview['error'] ?? 'Could not generate illustration', $preview['status'] ?? 502);
            }
            \App\Middleware\RateLimit::complete('diagram_illustration', (string)$userId, $usageToken);
            $saved = $this->saveUploadedImage($preview['data_url'], $userId);
            $deduct = CreditService::deductFor($user, 'ai', $cost, 'Diagram library illustration', 'diagram_illustration_' . bin2hex(random_bytes(16)));
            if (!$deduct['success']) {
                $this->deleteStoredImage($saved['relative'], $userId);
                Response::error($deduct['message'] ?? 'Credit deduction failed', 402);
            }

            $oldPath = $existing['image_path'] ?? null;
            if ($existing) {
                Diagram::update((int)$existing['id'], $userId, [
                    'title' => $title,
                    'description' => $description,
                    'mode' => 'illustration',
                    'source' => 'ai',
                    'diagram_spec' => $spec,
                    'image_path' => $saved['relative'],
                    'mime_type' => $saved['mime_type'],
                    'file_size' => $saved['file_size'],
                ]);
                if ($oldPath && $oldPath !== $saved['relative']) {
                    $this->deleteStoredImage($oldPath, $userId);
                }
                $diagramId = (int)$existing['id'];
            } else {
                $diagramId = Diagram::create([
                    'user_id' => $userId,
                    'title' => $title,
                    'description' => $description,
                    'mode' => 'illustration',
                    'source' => 'ai',
                    'diagram_spec' => $spec,
                    'image_path' => $saved['relative'],
                    'mime_type' => $saved['mime_type'],
                    'file_size' => $saved['file_size'],
                ]);
            }

            $created = Diagram::findById($diagramId, $userId);
            return array_merge($created ?? [], ['cost' => $cost, 'credits_left' => $deduct['new_balance']]);
        }

        $cost = (int)($_ENV['AI_DIAGRAM_CREDIT_COST'] ?? 10);
        if (!CreditService::canAffordFor($user, 'ai', $cost)) {
            Response::error('Insufficient credits for SVG generation (need ' . $cost . ')', 402);
        }
        $deduct = CreditService::deductFor($user, 'ai', $cost, 'Diagram library SVG', 'diagram_svg_' . bin2hex(random_bytes(16)));
        if (!$deduct['success']) {
            Response::error($deduct['message'] ?? 'Credit deduction failed', 402);
        }

        if ($existing) {
            if (!empty($existing['image_path'])) {
                $this->deleteStoredImage((string)$existing['image_path'], $userId);
            }
            Diagram::update((int)$existing['id'], $userId, [
                'title' => $title,
                'description' => $description,
                'mode' => 'precise_diagram',
                'source' => 'ai',
                'diagram_spec' => $spec,
                'image_path' => null,
                'mime_type' => null,
                'file_size' => null,
            ]);
            $diagramId = (int)$existing['id'];
        } else {
            $diagramId = Diagram::create([
                'user_id' => $userId,
                'title' => $title,
                'description' => $description,
                'mode' => 'precise_diagram',
                'source' => 'ai',
                'diagram_spec' => $spec,
                'image_path' => null,
                'mime_type' => null,
                'file_size' => null,
            ]);
        }
        $created = Diagram::findById($diagramId, $userId);
        return array_merge($created ?? [], ['cost' => $cost, 'credits_left' => $deduct['new_balance']]);
    }

    private function deleteStoredImage(string $relativePath, int $userId): void
    {
        $relativePath = ltrim($relativePath, '/');
        $prefix = 'uploads/diagrams/' . $userId . '/';
        if (!str_starts_with($relativePath, $prefix)) {
            return;
        }

        $storageRoot = realpath(__DIR__ . '/../../storage');
        $userRoot = realpath(__DIR__ . '/../../storage/uploads/diagrams/' . $userId);
        if (!$storageRoot || !$userRoot) {
            return;
        }

        $fullPath = $storageRoot . '/' . $relativePath;
        $real = realpath($fullPath);
        if ($real && str_starts_with($real, $userRoot) && is_file($real)) {
            @unlink($real);
        }
    }

    private function saveUploadedImage(string $dataUrl, int $userId): array
    {
        if (!preg_match('/^data:(image\/(?:jpeg|jpg|png|gif|webp));base64,(.+)$/s', $dataUrl, $matches)) {
            Response::error('A valid image data URL is required');
        }

        $mime = strtolower($matches[1]) === 'image/jpg' ? 'image/jpeg' : strtolower($matches[1]);
        $binary = base64_decode($matches[2], true);
        if ($binary === false || $binary === '') {
            Response::error('Invalid image data');
        }
        if (strlen($binary) > 6 * 1024 * 1024) {
            Response::error('Image is too large (max 6MB)');
        }

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            Response::error('Only JPEG, PNG, GIF, and WebP are allowed');
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        if ($detected && !str_starts_with($detected, 'image/')) {
            Response::error('Uploaded file is not a valid image');
        }

        $dir = __DIR__ . '/../../storage/uploads/diagrams/' . $userId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            Response::error('Could not create diagram storage directory', 500);
        }

        $filename = 'diagram_' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
        $fullPath = $dir . '/' . $filename;
        if (file_put_contents($fullPath, $binary) === false) {
            Response::error('Could not save diagram image', 500);
        }

        return [
            'relative' => 'uploads/diagrams/' . $userId . '/' . $filename,
            'mime_type' => $mime,
            'file_size' => strlen($binary),
        ];
    }
}
