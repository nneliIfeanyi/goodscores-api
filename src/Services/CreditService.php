<?php

namespace App\Services;

use App\Config\Database;
use App\Models\User;
use App\Models\School;
use PDO;

class CreditService
{
    public const OCR_COST = 35;
    public const PDF_COST = 0;
    public const OFFLINE_MIN = 200;

    public static function getUserEffectiveCredits(array $user): array
    {
        // School-linked users draw from school pool
        if (!empty($user['school_id'])) {
            $school = School::findById((int)$user['school_id']);
            if (!$school) {
                return ['balance' => 0, 'source' => 'none', 'is_unlimited' => false, 'can_use_pro' => false];
            }

            $isUnlimited = (bool)$school['is_unlimited'] && 
                           ($school['subscription_expiry'] === null || strtotime($school['subscription_expiry']) > time());

            $balance = (int)$school['credit_balance'];
            $canUsePro = $isUnlimited || $balance > 0;

            return [
                'balance'      => $isUnlimited ? -1 : $balance, // -1 = unlimited
                'source'       => 'school',
                'is_unlimited' => $isUnlimited,
                'can_use_pro'  => $canUsePro,
                'school'       => $school,
            ];
        }

        // Individual
        $isUnlimited = false; // individual unlimited handled via subscription table later
        $balance = (int)$user['credits'];
        return [
            'balance'      => $balance,
            'source'       => 'individual',
            'is_unlimited' => $isUnlimited,
            'can_use_pro'  => $balance > 0 || (bool)$user['is_pro'],
        ];
    }

    public static function canAfford(array $user, int $cost): bool
    {
        $info = self::getUserEffectiveCredits($user);
        if ($info['is_unlimited']) {
            return true;
        }
        return $info['balance'] >= $cost;
    }

    public static function canAffordFor(array $user, string $type, int $cost): bool
    {
        $info = self::getTypedBalance($user, $type);
        return $info['is_unlimited'] || $info['balance'] >= $cost;
    }

    public static function deductFor(array $user, string $type, int $cost, string $reason, ?string $reference = null): array
    {
        if ($reference !== null) {
            $existing = Database::getInstance()->prepare('SELECT id FROM credit_transactions WHERE user_id = ? AND reference = ? AND type = \'deduct\' LIMIT 1');
            $existing->execute([(int)$user['id'], $reference]);
            if ($existing->fetch()) return ['success' => true, 'new_balance' => self::getTypedBalance($user, $type)['balance']];
        }
        $info = self::getTypedBalance($user, $type);
        if ($info['is_unlimited']) return ['success' => true, 'new_balance' => -1];
        if ($info['balance'] < $cost) return ['success' => false, 'message' => 'Insufficient ' . $type . ' credits'];
        $column = $info['column'];
        $db = Database::getInstance();
        if ($info['source'] === 'school') {
            $stmt = $db->prepare("UPDATE schools SET {$column} = {$column} - ? WHERE id = ? AND {$column} >= ?");
            $stmt->execute([$cost, (int)$user['school_id'], $cost]);
        } else {
            $stmt = $db->prepare("UPDATE users SET {$column} = {$column} - ? WHERE id = ? AND {$column} >= ?");
            $stmt->execute([$cost, (int)$user['id'], $cost]);
        }
        if ($stmt->rowCount() !== 1) return ['success' => false, 'message' => 'Credit balance changed; please try again'];
        self::logTransaction($user['id'], $user['school_id'] ?? null, -$cost, $reason, $reference, 'deduct');
        return ['success' => true, 'new_balance' => $info['balance'] - $cost];
    }

    private static function getTypedBalance(array $user, string $type): array
    {
        if (!in_array($type, ['backup', 'ai', 'ocr', 'export'], true)) {
            throw new \InvalidArgumentException('Invalid credit type');
        }
        if (!empty($user['school_id'])) {
            $school = School::findById((int)$user['school_id']);
            return ['balance' => (int)($school['credit_balance'] ?? 0), 'source' => 'school', 'is_unlimited' => (bool)($school['is_unlimited'] ?? false), 'column' => 'credit_balance'];
        }
        return ['balance' => (int)($user['credits'] ?? 0), 'source' => 'individual', 'is_unlimited' => false, 'column' => 'credits'];
    }

    public static function getTypedBalanceForResponse(array $user, string $type): array
    {
        $info = self::getTypedBalance($user, $type);
        return ['balance' => $info['is_unlimited'] ? -1 : $info['balance'], 'is_unlimited' => $info['is_unlimited']];
    }

    public static function transactions(array $user, int $limit = 50): array
    {
        $db = Database::getInstance();
        $limit = min(100, max(1, $limit));
        $where = 'user_id = ?';
        $params = [(int)$user['id']];

        if (!empty($user['school_id'])) {
            $where = '(user_id = ? OR school_id = ?)';
            $params[] = (int)$user['school_id'];
        }

        $stmt = $db->prepare("SELECT id, amount, reason, reference, type, created_at FROM credit_transactions WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT {$limit}");
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public static function deduct(array $user, int $cost, string $reason, ?string $reference = null): array
    {
        $db = Database::getInstance();
        $info = self::getUserEffectiveCredits($user);

        if ($info['is_unlimited']) {
            self::logTransaction($user['id'], $user['school_id'] ?? null, -$cost, $reason, $reference, 'unlimited');
            return ['success' => true, 'new_balance' => -1, 'message' => 'Unlimited plan – no deduction'];
        }

        if ($info['balance'] < $cost) {
            return ['success' => false, 'message' => 'Insufficient credits'];
        }

        $newBalance = $info['balance'] - $cost;

        if ($info['source'] === 'school') {
            School::updateCredits((int)$user['school_id'], $newBalance);
        } else {
            User::updateCredits((int)$user['id'], $newBalance);
        }

        self::logTransaction($user['id'], $user['school_id'] ?? null, -$cost, $reason, $reference, 'deduct');

        // Offline lock check
        if ($newBalance < self::OFFLINE_MIN && empty($user['school_id'])) {
            // Individual only – school offline is controlled by school status
            $db->prepare('UPDATE users SET offline_unlocked = 0 WHERE id = ?')->execute([$user['id']]);
        }

        return ['success' => true, 'new_balance' => $newBalance];
    }

    public static function addCredits(int $userId, ?int $schoolId, int $amount, string $reason, ?string $reference = null): void
    {
        if ($schoolId) {
            $school = School::findById($schoolId);
            $new = ((int)$school['credit_balance']) + $amount;
            School::updateCredits($schoolId, $new);
        } else {
            $user = User::findById($userId);
            $new = ((int)$user['credits']) + $amount;
            User::updateCredits($userId, $new);
        }
        self::logTransaction($userId, $schoolId, $amount, $reason, $reference, 'credit');
    }

    private static function logTransaction(?int $userId, ?int $schoolId, int $amount, string $reason, ?string $reference, string $type): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO credit_transactions (user_id, school_id, amount, reason, reference, type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([$userId, $schoolId, $amount, $reason, $reference, $type]);
    }

    /**
     * @param string $pack pack code
     * @param string $planTarget pro | pro_plus (individuals only; schools always pro pool)
     */
    public static function applySubscription(array $user, string $pack, bool $isUnlimited = false, ?string $expiry = null, string $planTarget = 'pro', ?string $reference = null): array
    {
        $packs = [
            '500'    => 400,
            '1000'  => 1000,
            '2500'   => 3000,
            '5000'   => 6000,
            '25000'  => null,
            '50000'  => null,
            '120000' => null,
        ];

        if (!isset($packs[$pack])) {
            return ['success' => false, 'message' => 'Invalid pack'];
        }
        if ($reference) {
            $stmt = Database::getInstance()->prepare('SELECT id FROM credit_transactions WHERE reference = ? LIMIT 1');
            $stmt->execute([$reference]);
            if ($stmt->fetch()) {
                return ['success' => true, 'message' => 'Payment already applied', 'plan' => $planTarget];
            }
        }

        $credits = $packs[$pack];
        if (!in_array($planTarget, ['pro', 'pro_plus'], true)) {
            $planTarget = 'pro';
        }
        // School users cannot buy Pro Plus for personal multi-header
        if (!empty($user['school_id'])) {
            $planTarget = 'pro';
        }

        if ($user['school_id']) {
            $schoolId = (int)$user['school_id'];
            if ($isUnlimited || $credits === null) {
                $db = Database::getInstance();
                $db->prepare('UPDATE schools SET is_unlimited = 1, subscription_expiry = ? WHERE id = ?')
                   ->execute([$expiry, $schoolId]);
            } else {
                self::addCredits($user['id'], $schoolId, $credits, "Subscription pack ₦{$pack}", $reference ?? $pack);
            }
            User::setPro($user['id'], true, 'pro');
            User::unlockOffline($user['id']);
        } else {
            if ($credits === null) {
                User::setPro($user['id'], true, $planTarget);
                User::setSubscriptionExpiry($user['id'], $expiry);
            } else {
                self::addCredits($user['id'], null, $credits, "Subscription pack ₦{$pack}", $reference ?? $pack);
                User::setPro($user['id'], true, $planTarget);
            }
            User::unlockOffline($user['id']);
        }

        if ($reference && $credits === null) {
            self::logTransaction($user['id'], $user['school_id'] ?? null, 0, "Subscription payment ₦{$pack}", $reference, 'credit');
        }

        return [
            'success' => true,
            'message' => $planTarget === 'pro_plus'
                ? 'Pro Plus activated – multiple exam headers unlocked'
                : 'Subscription applied',
            'plan' => $planTarget,
        ];
    }
}

