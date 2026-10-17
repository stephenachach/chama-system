<?php
/**
 * includes/plans.php - the commercial catalogue (Phase 4).
 *
 * One source of truth for what you charge, so the landing page, the pilot
 * signup form and your pipeline view can never disagree.
 *
 * Pricing model (hybrid, as decided): a one-off setup fee per chama plus a
 * monthly subscription per chama. New groups start on a free pilot window;
 * when it ends the normal subscription machinery (expiry -> suspension ->
 * M-Pesa renewal) takes over automatically.
 */

if (!defined('PILOT_DAYS')) {
    define('PILOT_DAYS', 90);          // free pilot window for new chamas
}

if (!function_exists('plan_tiers')) {
    /**
     * @return array<string, array{label:string,setup:float,monthly:float,members:string,blurb:string,features:string[],highlight:bool}>
     */
    function plan_tiers(): array
    {
        return [
            'standard' => [
                'label'   => 'Standard',
                'setup'   => 5000,
                'monthly' => 500,
                'members' => 'Up to 40 members',
                'blurb'   => 'For neighbourhood and table-banking chamas getting organised.',
                'features' => [
                    'Contributions, dues & welfare records',
                    'Loans with automatic balances',
                    'Member self-pay by M-Pesa (STK)',
                    'SMS payment receipts',
                    'PDF member statements',
                ],
                'highlight' => false,
            ],
            'growth' => [
                'label'   => 'Growth',
                'setup'   => 7500,
                'monthly' => 1000,
                'members' => 'Up to 150 members',
                'blurb'   => 'For established chamas with monthly meetings and real float.',
                'features' => [
                    'Everything in Standard',
                    'Automatic monthly dues reminders (SMS)',
                    'Full audit log with before-values',
                    'Treasurer & approval workflows',
                    'Group monthly register PDFs',
                ],
                'highlight' => true,
            ],
            'premium' => [
                'label'   => 'Premium',
                'setup'   => 10000,
                'monthly' => 1500,
                'members' => 'Unlimited members',
                'blurb'   => 'For large chamas and groups preparing to become SACCOS.',
                'features' => [
                    'Everything in Growth',
                    'Unlimited members & multiple groups',
                    'Priority onboarding (we set up your data)',
                    'First in line for SACCO-grade features',
                    'Phone support during office hours',
                ],
                'highlight' => false,
            ],
        ];
    }
}

if (!function_exists('plan_tier')) {
    function plan_tier(string $key): ?array
    {
        $tiers = plan_tiers();
        return $tiers[$key] ?? null;
    }
}

if (!function_exists('pilot_ends_at')) {
    /** Expiry date granting a fresh chama its free pilot window. */
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
