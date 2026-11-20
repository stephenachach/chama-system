<?php
/**
 * includes/plans.php - the commercial catalogue (Phase 4/5).
 *
 * Prices are LAUNCH HYPOTHESES, not facts: they live in the `plans` table and
 * the platform operator edits them under Billing > Plans. This file only
 * reads them (with a built-in fallback so the site still works on a fresh
 * clone before migrations run).
 *
 * Money rules baked in here:
 *  - the GROUP pays the software subscription; members never pay us for access
 *  - we never take a percentage of group savings, contributions or loans
 *  - platform subscription money and group money are separate routes
 *    (see includes/mpesa.php: mpesa_group_route_status)
 *  - SMS is a prepaid, disclosed add-on - never "unlimited"
 */

if (!defined('PILOT_DAYS')) {
    define('PILOT_DAYS', 30);          // free trial window for new groups
}

if (!function_exists('plan_fallback_tiers')) {
    function plan_fallback_tiers(): array
    {
        return [
            'micro'        => ['label' => 'Micro - 8 to 10 active members',   'monthly' => 150,  'max_members' => 10,  'annual_months_pay' => 10, 'description' => 'Small table-banking and merry-go-round groups'],
            'small'        => ['label' => 'Small - 10 to 15 active members',  'monthly' => 250,  'max_members' => 15,  'annual_months_pay' => 10, 'description' => 'Growing savings groups'],
            'community'    => ['label' => 'Community - up to 20 members',     'monthly' => 500,  'max_members' => 20,  'annual_months_pay' => 10, 'description' => 'Established chamas with monthly meetings'],
            'growth'       => ['label' => 'Growth - up to 100 members',       'monthly' => 1200, 'max_members' => 100, 'annual_months_pay' => 10, 'description' => 'Large chamas with real float'],
            'welfare_plus' => ['label' => 'Welfare Plus - up to 250 members', 'monthly' => 2500, 'max_members' => 250, 'annual_months_pay' => 10, 'description' => 'Welfare societies and alumni groups'],
            'enterprise'   => ['label' => 'Large organisation - quoted',      'monthly' => 3500, 'max_members' => 0,   'annual_months_pay' => 10, 'description' => 'From KSh 3,500/month, quoted by requirements'],
        ];
    }
}

if (!function_exists('plan_decorate')) {
    /** Adds derived display fields used by the landing/start pages. */
    function plan_decorate(string $key, array $t): array
    {
        $max = (int)($t['max_members'] ?? 0);
        $t['members'] = $max > 0 ? 'Up to ' . $max . ' active members' : 'Unlimited members - quoted';
        $t['blurb'] = (string)($t['description'] ?? '');
        $t['setup'] = 0;                       // no fixed setup fee; assisted onboarding is optional and quoted
        $t['highlight'] = ($key === 'community');
        $t['features'] = array_values(array_filter([
            $max > 0 ? 'Up to ' . $max . ' active members' : 'Unlimited active members',
            'M-Pesa STK collections into YOUR OWN Paybill/Till',
            'Contributions, loans, welfare & statements',
            'SMS reminders from prepaid credit (never unlimited)',
            'Annual billing: pay ' . (int)($t['annual_months_pay'] ?? 10) . ' months, get 12',
        ]));
        return $t;
    }
}

if (!function_exists('plan_tiers')) {
    /** All active plans, keyed by plan_key, ordered for display. */
    function plan_tiers(): array
    {
        global $pdo;
        try {
            if (isset($pdo) && $pdo instanceof PDO) {
                $rows = $pdo->query('SELECT plan_key, label, monthly, max_members, annual_months_pay, description FROM plans WHERE active = 1 ORDER BY sort ASC, id ASC')->fetchAll();
                if ($rows) {
                    $out = [];
                    foreach ($rows as $r) {
                        $out[(string)$r['plan_key']] = [
                            'label'             => (string)$r['label'],
                            'monthly'           => (float)$r['monthly'],
                            'max_members'       => (int)$r['max_members'],
                            'annual_months_pay' => (int)$r['annual_months_pay'],
                            'description'       => (string)$r['description'],
                        ];
                    }
                    foreach ($out as $k => $t) {
                        $out[$k] = plan_decorate((string)$k, $t);
                    }
                    return $out;
                }
            }
        } catch (Throwable $ignored) {
        }
        $fb = plan_fallback_tiers();
        foreach ($fb as $k => $t) {
            $fb[$k] = plan_decorate((string)$k, $t);
        }
        return $fb;
    }
}

if (!function_exists('plan_tier')) {
    function plan_tier(string $key): ?array
    {
        $tiers = plan_tiers();
        return $tiers[$key] ?? null;
    }
}

if (!function_exists('plan_for_chama')) {
    /** The plan row for a chama (falls back to community if the key vanished). */
    function plan_for_chama(array $chama): array
    {
        $tiers = plan_tiers();
        $key = (string)($chama['tier'] ?? 'community');
        return $tiers[$key] ?? ($tiers['community'] ?? reset($tiers));
    }
}

if (!function_exists('plan_annual_price')) {
    /** Annual billing: pay annual_months_pay (default 10) months, get 12. */
    function plan_annual_price(array $plan): float
    {
        return (float)$plan['monthly'] * (int)($plan['annual_months_pay'] ?: 10);
    }
}

if (!function_exists('plan_active_member_count')) {
    function plan_active_member_count(int $chamaId): int
    {
        global $pdo;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM members WHERE chama_id = ? AND status = 'active'");
        $stmt->execute([$chamaId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('plan_member_slot_available')) {
    /**
     * Server-side entitlement check. Returns ['ok'=>bool,'message'=>string].
     * Called wherever a member can become active: create, edit-to-active,
     * registration approval.
     */
    function plan_member_slot_available(array $chama): array
    {
        $plan = plan_for_chama($chama);
        $limit = (int)$plan['max_members'];
        if ($limit <= 0) {
            return ['ok' => true, 'message' => ''];
        }
        $count = plan_active_member_count((int)$chama['id']);
        if ($count >= $limit) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'The %s plan covers up to %d active members and this group already has %d. Upgrade the plan (Billing > Plans) or mark inactive members first.',
                    $plan['label'], $limit, $count
                ),
            ];
        }
        return ['ok' => true, 'message' => ''];
    }
}

if (!function_exists('pilot_ends_at')) {
    /** Expiry date granting a fresh chama its free trial window. */
    function pilot_ends_at(?string $fromDate = null): string
    {
        $base = $fromDate ?: date('Y-m-d');
        return date('Y-m-d', strtotime($base . ' +' . PILOT_DAYS . ' days'));
    }
}

if (!function_exists('unique_chama_slug')) {
    /** Slugify + de-duplicate against existing chamas. */
    function unique_chama_slug(PDO $pdo, string $name): string
    {
        $base = slugify($name);
        if ($base === '') {
            $base = 'chama-' . date('ymd');
        }
        $slug = $base;
        $i = 2;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM chamas WHERE slug = ?');
        while (true) {
            $stmt->execute([$slug]);
            if ((int)$stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $i++;
        }
    }
}
