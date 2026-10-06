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
const APP_NAME = 'Community Welfare (Chama) Financial Management System';

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
