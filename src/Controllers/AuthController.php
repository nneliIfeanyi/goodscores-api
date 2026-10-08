<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\Jwt;
use App\Models\User;
use App\Models\School;
use App\Services\CreditService;
use App\Services\MailService;
use App\Config\Database;
use App\Models\PasswordReset;
use RuntimeException;

class AuthController
{
    public function register(): void
    {
        \App\Middleware\RateLimit::attempt('register', 10, 600, \App\Middleware\RateLimit::clientIp());
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $name     = trim($input['name'] ?? '');
        $email    = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        $type     = $input['type'] ?? 'individual'; // individual | school
        $schoolIdInput = trim($input['school_id'] ?? '');

        if (!$name || !$email || !$password) {
            Response::error('Name, email and password are required');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address');
        }

        if (strlen($password) < 6) {
            Response::error('Password must be at least 6 characters');
        }

        if (User::findByEmail($email)) {
            Response::error('Email already registered', 409);
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $initialCredits = (int)($_ENV['INITIAL_FREE_CREDITS'] ?? 200);

        // ===== SCHOOL REGISTRATION =====
        if ($type === 'school') {
            $schoolData = [
                'name'          => $name,
                'address'       => $input['address'] ?? null,
                'contact_email' => $email,
                'contact_phone' => $input['phone'] ?? null,
                'logo'          => null,
            ];

            $schoolDbId = School::create($schoolData);
            $school = School::findById($schoolDbId);

            $userId = User::create([
                'name'     => $input['admin_name'] ?? $name . ' Admin',
                'email'    => $email,
                'password' => $hashed,
                'role'     => 'school_admin',
                'school_id'=> $schoolDbId,
                'credits'  => 0, // school admin uses school pool
            ]);

            // School starts with 0 credits until first subscription
            // School ID is issued only after first successful paid subscription
            // For MVP we still generate the code on creation, but mark it inactive until paid

            $token = Jwt::encode([
                'sub'   => $userId,
                'email' => $email,
                'role'  => 'school_admin',
                'school_id' => $schoolDbId,
            ]);

            Response::success([
                'token' => $token,
                'user'  => [
                    'id'         => $userId,
                    'name'       => $input['admin_name'] ?? $name . ' Admin',
                    'email'      => $email,
                    'role'       => 'school_admin',
                    'school_id'  => $schoolDbId,
                    'school_code'=> $school['school_code'],
                    'credits'    => 0,
                    'is_pro'     => false,
                    'offline_unlocked' => false,
                ],
                'school' => [
                    'id'   => $schoolDbId,
                    'name' => $school['name'],
                    'code' => $school['school_code'],
                    'note' => 'School ID will be fully active after first paid subscription',
                ],
            ], 'School registered successfully', 201);
        }

        // ===== INDIVIDUAL / TEACHER REGISTRATION =====
        $role = 'individual';
        $schoolDbId = null;
        $isPro = false;
        $credits = $initialCredits;

        if ($schoolIdInput) {
            $school = School::findByCode($schoolIdInput);
            if (!$school) {
                Response::error('Invalid School ID');
            }
            // Check if school has active credits / unlimited
            $info = CreditService::getUserEffectiveCredits([
                'school_id' => $school['id'],
                'credits' => 0,
                'is_pro' => 0,
            ]);
            if (!$info['can_use_pro'] && !$info['is_unlimited']) {
                // Still allow join, but they won't get pro features until school has credits
            }
            $schoolDbId = (int)$school['id'];
            $role = 'teacher';
            $isPro = true;
            $credits = 0; // draws from school pool
        }

        $userId = User::create([
            'name'      => $name,
            'email'     => $email,
            'password'  => $hashed,
            'role'      => $role,
            'school_id' => $schoolDbId,
            'credits'   => $credits,
        ]);

        if ($isPro) {
            User::setPro($userId, true);
        }

        $token = Jwt::encode([
            'sub'       => $userId,
            'email'     => $email,
            'role'      => $role,
            'school_id' => $schoolDbId,
        ]);

        $user = User::findById($userId);

        Response::success([
            'token' => $token,
            'user'  => [
                'id'               => $userId,
                'name'             => $user['name'],
                'email'            => $user['email'],
                'role'             => $user['role'],
                'school_id'        => $user['school_id'],
                'credits'          => (int)$user['credits'],
                'is_pro'           => (bool)$user['is_pro'],
                'offline_unlocked' => (bool)$user['offline_unlocked'],
            ],
        ], 'Registered successfully', 201);
    }

    public function login(): void
    {
        \App\Middleware\RateLimit::attempt('login', 20, 300, \App\Middleware\RateLimit::clientIp());
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $email    = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            Response::error('Email and password required');
        }

        $user = User::findByEmail($email);
        if ($user && array_key_exists('is_active', $user) && !(bool)$user['is_active']) {
            Response::error('This account has been deactivated. Contact your school administrator.', 403);
        }
        if (!$user || !password_verify($password, $user['password'])) {
            Response::error('Invalid credentials', 401);
        }

        $token = Jwt::encode([
            'sub'       => $user['id'],
            'email'     => $user['email'],
            'role'      => $user['role'],
            'school_id' => $user['school_id'],
        ]);

        $creditInfo = CreditService::getUserEffectiveCredits($user);

        Response::success([
            'token' => $token,
            'user'  => [
                'id'               => $user['id'],
                'name'             => $user['name'],
                'email'            => $user['email'],
                'role'             => $user['role'],
                'school_id'        => $user['school_id'],
                'credits'          => $creditInfo['balance'],
                'credit_source'    => $creditInfo['source'],
                'is_unlimited'     => $creditInfo['is_unlimited'],
                'is_pro'           => (bool)$user['is_pro'] || $creditInfo['can_use_pro'],
                'offline_unlocked' => (bool)$user['offline_unlocked'],
            ],
        ]);
    }

    public function forgotPassword(): void
    {
        \App\Middleware\RateLimit::attempt('forgot-password', 5, 900, \App\Middleware\RateLimit::clientIp());
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $message = 'If an account exists for that email, a password reset link has been sent.';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::success(null, $message);
        }

        $user = User::findByEmail($email);
        if (!$user) {
            Response::success(null, $message);
        }

        $token = bin2hex(random_bytes(32));
        PasswordReset::create((int)$user['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 1800));
        $frontendUrl = rtrim((string)($_ENV['FRONTEND_URL'] ?? ''), '/');
        if ($frontendUrl === '') {
            Response::error('Password reset email is not configured', 503);
        }
        $resetPage = preg_match('/\.html$/i', $frontendUrl) ? $frontendUrl : $frontendUrl . '/index.html';
        $encodedToken = rawurlencode($token);
        $resetUrl = $resetPage . '?reset_token=' . $encodedToken . '#reset-password';

        try {
            MailService::sendPasswordReset(
                (string)$user['email'],
                (string)$user['name'],
                $resetUrl
            );
        } catch (RuntimeException $error) {
            error_log('Password reset email failed: ' . $error->getMessage());
            Response::error('Could not send password reset email', 503);
        }

        Response::success(null, $message);
    }

    public function resetPassword(): void
    {
        \App\Middleware\RateLimit::attempt('reset-password', 10, 900, \App\Middleware\RateLimit::clientIp());
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = trim((string)($input['token'] ?? ''));
        $password = (string)($input['password'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $token) || strlen($password) < 6) {
            Response::error('Invalid or expired password reset link', 400);
        }

        $reset = PasswordReset::findValid(hash('sha256', $token));
        if (!$reset) {
            Response::error('Invalid or expired password reset link', 400);
        }
        if (!User::updatePassword((int)$reset['user_id'], password_hash($password, PASSWORD_BCRYPT))) {
            Response::error('Could not reset password', 500);
        }
        PasswordReset::markUsed((int)$reset['id']);
        PasswordReset::invalidateForUser((int)$reset['user_id']);
        Response::success(null, 'Password reset successfully. You can now sign in.');
    }

    public function me(): void
    {
        $auth = \App\Middleware\Auth::requireAuth();
        $user = User::findById((int)$auth['sub']);
        if (!$user) {
            Response::error('User not found', 404);
        }

        $creditInfo = CreditService::getUserEffectiveCredits($user);
        $features = \App\Services\FeatureService::status($user);
        $school = null;
        if ($user['school_id']) {
            $school = School::findById((int)$user['school_id']);
        }
        $schoolSettings = $school ? (json_decode($school['paper_settings'] ?? '{}', true) ?: []) : [];
        $userPdfSettings = json_decode($user['pdf_settings'] ?? '{}', true) ?: [];
        $pdfFontSize = !empty($schoolSettings['pdf_font_size']) ? (float)$schoolSettings['pdf_font_size'] : (float)($user['pdf_font_size'] ?? 11);
        $pdfFontFamily = $schoolSettings['pdf_font_family'] ?? ($user['pdf_font_family'] ?? 'dejavusans');
        $pdfShowMarks = array_key_exists('show_marks', $schoolSettings)
            ? (bool)$schoolSettings['show_marks']
            : (bool)($user['pdf_show_marks'] ?? true);

        Response::success([
            'id'               => $user['id'],
            'name'             => $user['name'],
            'email'            => $user['email'],
            'role'             => $user['role'],
            'school_id'        => $user['school_id'],
            'credits'          => $creditInfo['balance'],
            'credit_source'    => $creditInfo['source'],
            'is_unlimited'     => $creditInfo['is_unlimited'],
            'is_pro'           => $features['is_pro'],
            'plan'             => $features['plan'],
            'is_pro_plus'      => $features['is_pro_plus'],
            'offline_unlocked' => (bool)$user['offline_unlocked'],
            'pdf_font_size'    => $pdfFontSize,
            'pdf_font_family'  => $pdfFontFamily,
            'pdf_show_marks'   => $pdfShowMarks,
            'pdf_settings'     => $userPdfSettings,
            'pdf_settings_locked' => !empty($user['school_id']) && $user['role'] !== 'school_admin',
            'features'         => $features,
            'school'           => $school ? [
                'id'   => $school['id'],
                'name' => $school['name'],
                'code' => $school['school_code'],
                'logo' => $school['logo'],
                'address' => $school['address'],
                'paper_settings' => $schoolSettings,
            ] : null,
            'current_session'  => $_ENV['CURRENT_SESSION'] ?? '',
        ]);
    }

    public function updateSettings(): void
    {
        $auth = \App\Middleware\Auth::requireAuth();
        $user = User::findById((int)$auth['sub']);
        if (!$user) {
            Response::error('User not found', 404);
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        if (array_key_exists('school_id', $input)) {
            if ($user['role'] !== 'individual' || !empty($user['school_id'])) {
                Response::error('Only unlinked individual accounts can join a school', 403);
            }

            $schoolCode = strtoupper(trim((string)$input['school_id']));
            if ($schoolCode === '') {
                Response::error('School ID is required');
            }
            $school = School::findByCode($schoolCode);
            if (!$school) {
                Response::error('Invalid School ID', 404);
            }
            if (!User::linkToSchool((int)$auth['sub'], (int)$school['id'])) {
                Response::error('Could not link your account to the school', 500);
            }

            $linkedUser = User::findById((int)$auth['sub']);
            $token = \App\Helpers\Jwt::encode([
                'sub' => $linkedUser['id'],
                'email' => $linkedUser['email'],
                'role' => $linkedUser['role'],
                'school_id' => $linkedUser['school_id'],
            ]);

            Response::success([
                'token' => $token,
                'school_id' => $linkedUser['school_id'],
                'role' => $linkedUser['role'],
                'is_pro' => true,
                'school' => [
                    'id' => $school['id'],
                    'name' => $school['name'],
                    'code' => $school['school_code'],
                ],
            ], 'Account linked to school successfully');
        }

        $fontSize = (float)($input['pdf_font_size'] ?? 11);
        $fontFamily = strtolower(trim((string)($input['pdf_font_family'] ?? 'dejavusans')));
        $showMarks = filter_var($input['pdf_show_marks'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $allowedFamilies = ['dejavusans', 'dejavuserif', 'freesans', 'freeserif', 'freemono'];

        if ($fontSize < 8 || $fontSize > 18) {
            Response::error('PDF font size must be between 8 and 18 points');
        }
        if (!in_array($fontFamily, $allowedFamilies, true)) {
            Response::error('Unsupported PDF font style');
        }
        $isSchoolAdmin = $user['role'] === 'school_admin' && !empty($user['school_id']);
        if (!empty($user['school_id']) && !$isSchoolAdmin) {
            Response::error('PDF settings are controlled by your school administrator', 403);
        }

        $settings = is_array($input['pdf_settings'] ?? null) ? $input['pdf_settings'] : [];
        $settings['paper_format'] = in_array(($settings['paper_format'] ?? 'columns'), ['columns', 'inline_options'], true)
            ? $settings['paper_format'] : 'columns';
        $settings['paper_size'] = in_array(strtoupper((string)($settings['paper_size'] ?? 'A4')), ['A4', 'LETTER', 'LEGAL'], true)
            ? strtoupper((string)$settings['paper_size']) : 'A4';
        $settings['orientation'] = in_array(($settings['orientation'] ?? 'portrait'), ['portrait', 'landscape'], true)
            ? $settings['orientation'] : 'portrait';
        foreach (['margin_top', 'margin_bottom', 'margin_left', 'margin_right'] as $margin) {
            $value = (float)($settings[$margin] ?? 10);
            if ($value < 0 || $value > 50) Response::error('PDF margins must be between 0 and 50 mm');
            $settings[$margin] = $value;
        }
        $headerHeight = (float)($settings['header_height'] ?? 80);
        if ($headerHeight < 0 || $headerHeight > 300) Response::error('PDF header height must be between 0 and 300 px');
        $settings['header_height'] = $headerHeight;
        foreach (['show_logo', 'show_school_name'] as $toggle) {
            $settings[$toggle] = filter_var($settings[$toggle] ?? true, FILTER_VALIDATE_BOOLEAN);
        }
        $settings['footer_text'] = array_key_exists('footer_text', $settings)
            ? trim((string)$settings['footer_text'])
            : 'End of Paper';

        $settings['pdf_font_size'] = $fontSize;
        $settings['pdf_font_family'] = $fontFamily;
        $settings['show_marks'] = $showMarks === null ? true : $showMarks;
        $saved = $isSchoolAdmin
            ? School::updatePaperSettings((int)$user['school_id'], $settings)
            : User::updatePdfSettings((int)$auth['sub'], $fontSize, $fontFamily, $settings['show_marks'])
                && User::updatePdfPreferences((int)$auth['sub'], $settings);
        if (!$saved) {
            Response::error('Could not save PDF settings', 500);
        }
        Response::success([
            'pdf_font_size' => $fontSize,
            'pdf_font_family' => $fontFamily,
            'pdf_show_marks' => $showMarks === null ? true : $showMarks,
            'pdf_settings' => $settings,
        ], 'PDF settings saved');
    }

    public function deleteAccount(): void
    {
        $auth = \App\Middleware\Auth::requireAuth();
        $user = User::findById((int)$auth['sub']);
        if (!$user) Response::error('User not found', 404);
        if ($user['role'] !== 'individual' || $user['school_id'] !== null) {
            Response::error('School-linked accounts cannot delete themselves', 403);
        }
        if (!User::deleteIndividual((int)$auth['sub'])) {
            Response::error('Account could not be deleted', 500);
        }
        Response::success(null, 'Account deleted');
    }
}
