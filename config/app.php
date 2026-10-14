<?php
// App configuration.
// BASE_URL is detected automatically so the system works whether the folder is named
// chama_system, chama-system, project, or placed directly inside htdocs.
$documentRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__));
$projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$baseUrl = str_replace($documentRoot, '', $projectRoot);
$baseUrl = '/' . trim($baseUrl, '/');
if ($baseUrl === '/') {
    $baseUrl = '';
}
define('BASE_URL', $baseUrl);
const APP_NAME = 'Chama Management System';

// Platform operator account (created automatically on first run of an upgraded
// installation). Update these two constants before going live, then change the
// actual password after first login via My Account -> Change Password.
const SUPERADMIN_EMAIL = 'superadmin@chama.local';
const SUPERADMIN_INITIAL_PASSWORD = 'Chama@2026';

// Name given to the first (default) chama when upgrading an older installation.
const DEFAULT_CHAMA_NAME = 'Default Chama';

// ---------------------------------------------------------------------------
// Subscription billing (Phase 2)
// ---------------------------------------------------------------------------
// Days a chama keeps working after its plan expiry before being auto-suspended.
const SUBSCRIPTION_GRACE_DAYS = 3;
// Days before expiry that the "expires soon" warning starts showing.
const SUBSCRIPTION_REMINDER_DAYS = 7;
// Months of service a single payment buys.
const SUBSCRIPTION_BILLING_MONTHS = 1;


function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function money($amount): string
{
    return 'KES ' . number_format((float)$amount, 2);
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function slugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'chama-' . substr(md5(uniqid('', true)), 0, 6);
}

function loanEligibility(array $loan): array
{
    global $pdo;
    $status = $loan['status'] ?? null;
    if ($status !== 'pending') {
        return [
            'status' => 'complete',
            'label' => 'N/A',
            'class' => 'secondary',
            'reasons' => [],
        ];
    }

    $reasons = [];
    $memberId = (int)($loan['member_id'] ?? 0);
    $memberStatus = $loan['member_status'] ?? null;

    if ($memberStatus !== 'active') {
        $reasons[] = 'Member is not active';
    }

    $loanAmount = (float)($loan['amount'] ?? 0);
    $dateThreshold = date('Y-m-d', strtotime('-6 months'));
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM contributions WHERE member_id = ? AND contribution_date >= ?');
    $stmt->execute([$memberId, $dateThreshold]);
    $recentContributions = (float)$stmt->fetchColumn();

    if ($loanAmount > 0 && $recentContributions < $loanAmount * 0.25) {
        $reasons[] = 'Low contribution history';
    }

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(balance),0) FROM loans WHERE member_id = ? AND status = ? AND id <> ?');
    $stmt->execute([$memberId, 'approved', $loan['id'] ?? 0]);
    $existingBalance = (float)$stmt->fetchColumn();

    if ($existingBalance > 0) {
        $reasons[] = 'Existing approved loan balance';
    }

    if (empty($reasons)) {
        return [
            'status' => 'eligible',
            'label' => 'Eligible',
            'class' => 'success',
            'reasons' => [],
        ];
    }

    return [
        'status' => 'review',
        'label' => 'Needs review',
        'class' => 'warning',
        'reasons' => $reasons,
    ];
}

function loanEligibilityBadge(array $loan): string
{
    $info = loanEligibility($loan);
    return '<span class="badge bg-' . e($info['class']) . '">' . e($info['label']) . '</span>';
}
