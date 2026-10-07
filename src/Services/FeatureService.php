<?php

namespace App\Services;

use App\Models\User;
use App\Models\School;

/**
 * Central feature gating: OCR, offline, Pro Plus headers.
 */
class FeatureService
{
    public static function status(array $user): array
    {
        $credits = CreditService::getUserEffectiveCredits($user);
        $plan = $user['plan'] ?? ($user['is_pro'] ? 'pro' : 'free');

        // Expired unlimited?
        $expired = false;
        if (!empty($user['school_id'])) {
            $school = $credits['school'] ?? School::findById((int)$user['school_id']);
            if ($school && !empty($school['subscription_expiry']) && strtotime($school['subscription_expiry']) < time()) {
                if (empty($school['is_unlimited']) || (int)$school['credit_balance'] <= 0) {
                    $expired = true;
                }
            }
            // School unlimited expired
            if ($school && (int)$school['is_unlimited'] === 1 && !empty($school['subscription_expiry'])
                && strtotime($school['subscription_expiry']) < time()) {
                $expired = true;
                $credits['is_unlimited'] = false;
                $credits['can_use_pro'] = ((int)$school['credit_balance']) > 0;
            }
        } else {
            if (!empty($user['subscription_expiry']) && strtotime($user['subscription_expiry']) < time()) {
                if ($plan !== 'free' && (int)$user['credits'] <= 0) {
                    $expired = true;
                    $plan = 'free';
                }
            }
        }

        $canAi = CreditService::canAffordFor($user, 'ai', (int)($_ENV['AI_CREDIT_COST'] ?? 35));
        $canOcr = CreditService::canAffordFor($user, 'ocr', (int)($_ENV['OCR_CREDIT_COST'] ?? CreditService::OCR_COST));
        $canPdf = true;

        // Manual question drafting and paper output are available to every account.
        // OCR and AI still require connectivity and credits.
        $offlineOk = true;

        // School teachers blocked when pool dead
        if (!empty($user['school_id']) && !$credits['can_use_pro'] && !$credits['is_unlimited']) {
            $canOcr = false;
            // offline for school follows school health lightly
        }

        return [
            'plan'            => $plan,
            'is_pro'          => in_array($plan, ['pro', 'pro_plus'], true) || (bool)$user['is_pro'],
            'is_pro_plus'     => $plan === 'pro_plus',
            'can_ocr'         => $canOcr,
            'can_ai'          => $canAi,
            'can_pdf'         => $canPdf,
            'offline_ok'      => $offlineOk,
            'credits'         => $credits,
            'subscription_expired' => $expired,
            'max_headers'     => $plan === 'pro_plus' ? 10 : ($plan === 'pro' ? 1 : 1),
        ];
    }

    public static function requireCredits(array $user, int $cost, string $feature = 'this feature'): void
    {
        $status = self::status($user);
        if ($feature === 'ocr' && !$status['can_ocr']) {
            \App\Helpers\Response::error('Cannot use OCR – insufficient credits or school pool expired', 402);
        }
        if ($feature === 'pdf' && !$status['can_pdf']) {
            \App\Helpers\Response::error('Cannot export PDF – insufficient credits or school pool expired', 402);
        }
        if (!CreditService::canAfford($user, $cost)) {
            \App\Helpers\Response::error("Insufficient credits for {$feature} (need {$cost})", 402);
        }
    }
}
