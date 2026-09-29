<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\Auth;
use App\Models\User;
use App\Models\Header;

class HeaderController
{
    public function index(): void
    {
        $auth = Auth::requireAuth();
        Response::success(Header::listByUser((int)$auth['sub']));
    }

    public function store(): void
    {
        $auth = Auth::requireAuth();
        $user = User::findById((int)$auth['sub']);
        if (!$user) {
            Response::error('User not found', 404);
        }

        // School-linked users use school header – no personal multi-header
        if (!empty($user['school_id'])) {
            Response::error('School accounts use the school header automatically', 400);
        }

        $count = Header::count((int)$auth['sub']);
        if ($count >= 1) {
            Response::error('Only one exam header is allowed. Edit the existing header in Account settings.', 400);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($input['school_name'] ?? '');
        if ($name === '') {
            Response::error('school_name is required');
        }

        $id = Header::create((int)$auth['sub'], [
            'label'       => $input['label'] ?? $name,
            'school_name' => $name,
            'extra_line'  => $input['extra_line'] ?? null,
            'logo_path'   => $input['logo_path'] ?? null,
            'is_default'  => !empty($input['is_default']) || $count === 0,
        ]);

        Response::success(Header::find($id, (int)$auth['sub']), 'Header saved', 201);
    }

    public function update(int $id): void
    {
        $auth = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($input['school_name'] ?? '');
        if ($name === '') {
            Response::error('school_name is required');
        }

        $ok = Header::update($id, (int)$auth['sub'], [
            'label'       => $input['label'] ?? $name,
            'school_name' => $name,
            'extra_line'  => $input['extra_line'] ?? null,
            'logo_path'   => $input['logo_path'] ?? null,
            'is_default'  => !empty($input['is_default']),
        ]);
        if (!$ok) {
            Response::error('Header not found', 404);
        }
        Response::success(Header::find($id, (int)$auth['sub']), 'Header updated');
    }

    public function destroy(int $id): void
    {
        Auth::requireAuth();
        Response::error('Exam headers cannot be deleted. Edit the header in Account settings.', 403);
    }

    public function uploadLogo(int $id): void
    {
        $auth = Auth::requireAuth();
        $user = User::findById((int)$auth['sub']);
        if (!$user || !empty($user['school_id'])) {
            Response::error('School-linked users use the school logo automatically', 403);
        }
        $header = Header::find($id, (int)$auth['sub']);
        if (!$header) Response::error('Header not found', 404);
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $base64 = (string)($input['image'] ?? '');
        $matches = [];
        if (!$base64 || !preg_match('/^data:(image\/(?:png|jpe?g|gif|webp));base64,([A-Za-z0-9+\/=]+)$/i', $base64, $matches)) {
            Response::error('A PNG, JPG, GIF, or WebP logo is required');
        }
        $binary = base64_decode($matches[2], true);
        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) Response::error('Logo must be 5MB or smaller');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) Response::error('Invalid logo image');
        $dir = __DIR__ . '/../../storage/uploads/logos';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
        $relative = 'uploads/logos/header_' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        file_put_contents(__DIR__ . '/../../storage/' . $relative, $binary);
        Header::update($id, (int)$auth['sub'], [
            'label' => $header['label'], 'school_name' => $header['school_name'],
            'extra_line' => $header['extra_line'], 'logo_path' => $relative,
            'is_default' => !empty($header['is_default']),
        ]);
        Response::success(['logo_path' => $relative], 'Header logo updated');
    }
}
