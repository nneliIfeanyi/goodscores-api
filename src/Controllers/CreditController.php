<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\Auth;
use App\Models\User;
use App\Services\CreditService;
use App\Services\FeatureService;
use App\Services\PaystackService;
use RuntimeException;

class CreditController
{
    public static function canPurchaseCredits(array $user): bool
    {
        return empty($user['school_id']) || ($user['role'] ?? '') === 'school_admin';
    }

    public function balance(): void
    {
        $auth = Auth::requireAuth();
        $user = User::findById((int)$auth['sub']);
        if (!$user) {
            Response::error('User not found', 404);
        }

        $info = CreditService::getUserEffectiveCredits($user);
        $features = FeatureService::status($user);
        $typed = [];
        foreach (['ai', 'ocr', 'export'] as $type) {
            $typed[$type] = CreditService::getTypedBalanceForResponse($user, $type);
        }
        Response::success(array_merge($info, ['balances' => $typed, 'features' => $features]));
    }

    public function transactions(): void
    {
        $auth = Auth::requireAuth();
        $user = User::findById((int)$auth['sub']);
        if (!$user) {
            Response::error('User not found', 404);
        }

        Response::success(CreditService::transactions($user));
    }

        public function authorizeExport(): void
        {
            $auth = Auth::requireAuth();
            $user = User::findById((int)$auth['sub']);
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $reference = trim((string)($input['client_reference_id'] ?? ''));
            if ($reference === '' || strlen($reference) > 100) Response::error('A valid export reference is required');
            $cost = (int)($_ENV['PDF_EXPORT_CREDIT_COST'] ?? CreditService::PDF_COST);
            $result = CreditService::deductFor($user, 'export', $cost, 'Local paper export', $reference);
            if (!$result['success']) Response::error($result['message'] ?? 'Export credit authorization failed', 402);
            Response::success(['authorized' => true, 'cost' => $cost, 'credits_left' => $result['new_balance']], 'Export authorized');
        }

    public function initializePayment(): void
    {
        $auth = Auth::requireAuth();
        $user = User::findById((int)$auth['sub']);
        if (!$user) {
            Response::error('User not found', 404);
        }
        if (!self::canPurchaseCredits($user)) {
            Response::error('School-linked users cannot purchase credits', 403);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $pack = (string)($input['pack'] ?? '');
        $plan = (string)($input['plan'] ?? 'pro');

        $amounts = ['500' => 500, '1000' => 1000, '2500' => 2500, '5000' => 5000, '25000' => 25000, '50000' => 50000, '120000' => 120000];
        if (!isset($amounts[$pack]) || !in_array($plan, ['pro', 'pro_plus'], true)) {
            Response::error('Invalid payment plan');
        }
        try {
            $callback = trim((string)($_ENV['PAYSTACK_CALLBACK_URL'] ?? '')) ?: null;
            $payment = PaystackService::initialize((string)$user['email'], $amounts[$pack] * 100, [
                'user_id' => (int)$user['id'],
                'pack' => $pack,
                'plan' => $plan,
                'merchant_name' => 'Goodscores Stanvic Concepts',
            ], $callback);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 503);
        }
        Response::success(['authorization_url' => $payment['authorization_url'] ?? null, 'reference' => $payment['reference'] ?? null]);
    }

    public function verifyPayment(): void
    {
        $auth = Auth::requireAuth();
        $user = User::findById((int)$auth['sub']);
        if (!$user) {
            Response::error('User not found', 404);
        }
        if (!self::canPurchaseCredits($user)) {
            Response::error('School-linked users cannot purchase credits', 403);
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        try {
            $payment = PaystackService::verify((string)($input['reference'] ?? ''));
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 502);
        }
        $metadata = $payment['metadata'] ?? [];
        if (($payment['status'] ?? '') !== 'success' || (int)($metadata['user_id'] ?? 0) !== (int)$user['id']) {
            Response::error('Payment could not be verified', 400);
        }
        $pack = (string)($metadata['pack'] ?? '');
        $plan = (string)($metadata['plan'] ?? 'pro');
        $amounts = ['500' => 500, '1000' => 1000, '2500' => 2500, '5000' => 5000, '25000' => 25000, '50000' => 50000, '120000' => 120000];
        if (!isset($amounts[$pack]) || (int)($payment['amount'] ?? 0) !== $amounts[$pack] * 100) {
            Response::error('Payment amount does not match the selected pack', 400);
        }
        $expiryMap = ['25000' => '+1 month', '50000' => '+3 months', '120000' => '+1 year'];
        $reference = (string)($payment['reference'] ?? ($input['reference'] ?? ''));
        $result = CreditService::applySubscription($user, $pack, isset($expiryMap[$pack]), isset($expiryMap[$pack]) ? date('Y-m-d H:i:s', strtotime($expiryMap[$pack])) : null, $plan, $reference);
        if (!$result['success']) {
            Response::error($result['message']);
        }
        $user = User::findById((int)$auth['sub']);
        Response::success(['message' => $result['message'], 'credits' => CreditService::getUserEffectiveCredits($user), 'offline_unlocked' => true, 'is_pro' => true, 'plan' => $result['plan'] ?? $plan, 'features' => FeatureService::status($user)]);
    }
}
