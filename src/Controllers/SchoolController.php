<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\Auth;
use App\Models\User;
use App\Models\School;
use App\Services\CreditService;
use App\Config\Database;

class SchoolController
{
    public function teachers(): void
    {
        $auth = Auth::requireRole(['school_admin']);
        $schoolId = (int)($auth['school_id'] ?? 0);
        if (!$schoolId) {
            Response::error('No school associated', 400);
        }

        $teachers = School::getTeachers($schoolId);
        Response::success($teachers);
    }

    public function createTeacher(): void
    {
        $auth = Auth::requireRole(['school_admin']);
        $schoolId = (int)($auth['school_id'] ?? 0);
        if (!$schoolId) {
            Response::error('No school associated', 400);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name  = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? bin2hex(random_bytes(4));

        if (!$name || !$email) {
            Response::error('Name and email required');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email');
        }
        if (User::findByEmail($email)) {
            Response::error('Email already exists', 409);
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $userId = User::create([
            'name'      => $name,
            'email'     => $email,
            'password'  => $hashed,
            'role'      => 'teacher',
            'school_id' => $schoolId,
            'credits'   => 0,
            'plan'      => 'pro',
        ]);
        User::setPro($userId, true, 'pro');
        User::unlockOffline($userId);

        Response::success([
            'id'       => $userId,
            'name'     => $name,
            'email'    => $email,
            'password' => $password,
            'role'     => 'teacher',
        ], 'Teacher account created', 201);
    }

    public function removeTeacher(int $userId): void
    {
        $auth = Auth::requireRole(['school_admin']);
        $schoolId = (int)($auth['school_id'] ?? 0);
        if (!$schoolId || !User::deleteTeacher($userId, $schoolId)) {
            Response::error('Teacher not found', 404);
        }
        Response::success(null, 'Teacher account deleted');
    }

    public function updateTeacher(int $userId): void
    {
        $auth = Auth::requireRole(['school_admin']);
        $schoolId = (int)($auth['school_id'] ?? 0);
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if (!$name || !$email) Response::error('Name and email required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Invalid email');

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, role FROM users WHERE id = ? AND school_id = ?');
        $stmt->execute([$userId, $schoolId]);
        $teacher = $stmt->fetch();
        if (!$teacher || $teacher['role'] !== 'teacher') Response::error('Teacher not found', 404);

        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) Response::error('Email already exists', 409);

        $passwordHash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;
        if (!User::updateTeacher($userId, $name, $email, $passwordHash)) {
            Response::error('Could not update teacher account', 500);
        }
        Response::success(null, 'Teacher account updated');
    }

    public function setTeacherActive(int $userId): void
    {
        $auth = Auth::requireRole(['school_admin']);
        $schoolId = (int)($auth['school_id'] ?? 0);
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $active = (bool)($input['active'] ?? false);
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, role FROM users WHERE id = ? AND school_id = ?');
        $stmt->execute([$userId, $schoolId]);
        $teacher = $stmt->fetch();
        if (!$teacher || $teacher['role'] !== 'teacher') Response::error('Teacher not found', 404);
        if (!User::setActive($userId, $active)) Response::error('Could not update teacher account', 500);
        Response::success(null, $active ? 'Teacher account activated' : 'Teacher account deactivated');
    }

    public function info(): void
    {
        $auth = Auth::requireAuth();
        $schoolId = (int)($auth['school_id'] ?? 0);
        if (!$schoolId) {
            Response::error('No school associated', 400);
        }

        $school = School::findById($schoolId);
        if (!$school) {
            Response::error('School not found', 404);
        }

        $creditInfo = CreditService::getUserEffectiveCredits([
            'school_id' => $schoolId,
            'credits' => 0,
            'is_pro' => 1,
        ]);

        Response::success([
            'id'                  => $school['id'],
            'name'                => $school['name'],
            'code'                => $school['school_code'],
            'logo'                => $school['logo'],
            'address'             => $school['address'],
            'contact_email'       => $school['contact_email'],
            'contact_phone'       => $school['contact_phone'],
            'paper_settings'      => json_decode($school['paper_settings'] ?? '{}', true),
            'credit_balance'      => $creditInfo['balance'],
            'is_unlimited'        => $creditInfo['is_unlimited'],
            'subscription_expiry' => $school['subscription_expiry'],
            'teacher_count'       => count(School::getTeachers($schoolId)),
        ]);
    }

    public function update(): void
    {
        $auth = Auth::requireRole(['school_admin']);
        $schoolId = (int)($auth['school_id'] ?? 0);
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $db = Database::getInstance();
        $paperSettings = null;
        if (isset($input['paper_settings'])) {
            $current = School::findById($schoolId);
            $paperSettings = json_decode($current['paper_settings'] ?? '{}', true) ?: [];
            $requested = is_array($input['paper_settings']) ? $input['paper_settings'] : [];
            if (isset($requested['pdf_font_size'])) {
                $fontSize = (float)$requested['pdf_font_size'];
                if ($fontSize < 8 || $fontSize > 18) {
                    Response::error('PDF font size must be between 8 and 18 points');
                }
                $paperSettings['pdf_font_size'] = $fontSize;
            }
            if (isset($requested['pdf_font_family'])) {
                $fontFamily = strtolower(trim((string)$requested['pdf_font_family']));
                if (!in_array($fontFamily, ['dejavusans', 'dejavuserif', 'freesans', 'freeserif', 'freemono'], true)) {
                    Response::error('Unsupported PDF font style');
                }
                $paperSettings['pdf_font_family'] = $fontFamily;
            }
            if (array_key_exists('show_marks', $requested)) {
                $paperSettings['show_marks'] = (bool)$requested['show_marks'];
            }
            if (isset($requested['paper_size'])) {
                $paperSize = strtoupper(trim((string)$requested['paper_size']));
                if (!in_array($paperSize, ['A4', 'LETTER', 'LEGAL'], true)) {
                    Response::error('Unsupported paper size');
                }
                $paperSettings['paper_size'] = $paperSize;
            }
            if (isset($requested['orientation'])) {
                $orientation = strtolower(trim((string)$requested['orientation']));
                if (!in_array($orientation, ['portrait', 'landscape'], true)) {
                    Response::error('Unsupported paper orientation');
                }
                $paperSettings['orientation'] = $orientation;
            }
            foreach (['margin_top', 'margin_bottom', 'margin_left', 'margin_right'] as $margin) {
                if (isset($requested[$margin])) {
                    $value = (float)$requested[$margin];
                    if ($value < 0 || $value > 50) Response::error('PDF margins must be between 0 and 50 mm');
                    $paperSettings[$margin] = $value;
                }
            }
            if (isset($requested['header_height'])) {
                $headerHeight = (float)$requested['header_height'];
                if ($headerHeight < 0 || $headerHeight > 300) Response::error('PDF header height must be between 0 and 300 px');
                $paperSettings['header_height'] = $headerHeight;
            }
            if (array_key_exists('footer_text', $requested)) {
                $paperSettings['footer_text'] = trim((string)$requested['footer_text']);
            }
            foreach (['show_logo', 'show_school_name'] as $toggle) {
                if (array_key_exists($toggle, $requested)) {
                    $paperSettings[$toggle] = (bool)$requested[$toggle];
                }
            }
        }
        $stmt = $db->prepare('
            UPDATE schools SET
                name = COALESCE(?, name),
                address = COALESCE(?, address),
                contact_phone = COALESCE(?, contact_phone),
                paper_settings = COALESCE(?, paper_settings),
                logo = COALESCE(?, logo)
            WHERE id = ?
        ');
        $stmt->execute([
            isset($input['name']) ? trim($input['name']) : null,
            $input['address'] ?? null,
            $input['contact_phone'] ?? null,
            $paperSettings !== null ? json_encode($paperSettings) : null,
            $input['logo'] ?? null,
            $schoolId,
        ]);

        $this->info();
    }

    public function uploadLogo(): void
    {
        $auth = Auth::requireRole(['school_admin']);
        $schoolId = (int)($auth['school_id'] ?? 0);
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $base64 = (string)($input['image'] ?? '');
        $matches = [];
        if (!$base64 || !preg_match('/^data:(image\/(?:png|jpe?g|gif|webp));base64,([A-Za-z0-9+\/=]+)$/i', $base64, $matches)) {
            Response::error('A PNG, JPG, GIF, or WebP logo is required');
        }
        $binary = base64_decode($matches[2], true);
        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
            Response::error('Logo must be 5MB or smaller');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            Response::error('Invalid logo image');
        }
        $dir = __DIR__ . '/../../storage/uploads/logos';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
        $relative = 'uploads/logos/school_' . $schoolId . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        file_put_contents(__DIR__ . '/../../storage/' . $relative, $binary);
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE schools SET logo = ? WHERE id = ?');
        $stmt->execute([$relative, $schoolId]);
        Response::success(['logo' => $relative], 'School logo updated');
    }
}
