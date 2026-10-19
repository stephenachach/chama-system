<?php
define('CSRF_EXEMPT', true);   // public form / machine endpoint: CSRF handled per-flow
/**
 * Subscription maintenance job.
 *
 * Suspends every chama whose plan expired past the grace period, and reports
 * which groups are about to expire.
 *
 * The app also runs this check lazily on page loads (every 5 minutes), so the
 * cron job is optional - it just guarantees suspension happens even if nobody
 * logs in for days.
 *
 * CLI:   php cron/subscriptions.php
 * Cron:  0 6 * * * php /path/to/chama_system/cron/subscriptions.php
 * URL:   cron/subscriptions.php?token=<MPESA_CALLBACK_TOKEN>
 */

require_once __DIR__ . '/../config/auth.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    $token = (string)($_GET['token'] ?? '');
    if (!hash_equals((string)MPESA_CALLBACK_TOKEN, $token) && !is_superadmin()) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "Forbidden. Add ?token=<your MPESA_CALLBACK_TOKEN> or log in as the platform operator.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$suspended = enforce_subscriptions();

$stmt = $pdo->query(
    "SELECT id, name, plan, monthly_fee, plan_expires_at, status, suspension_reason
       FROM chamas ORDER BY (plan_expires_at IS NULL), plan_expires_at ASC"
);
$chamas = $stmt->fetchAll();

$lines = [];
$lines[] = 'Subscription check - ' . date('Y-m-d H:i:s');
$lines[] = 'Grace period: ' . (int)SUBSCRIPTION_GRACE_DAYS . ' day(s)';
$lines[] = 'Newly suspended: ' . $suspended;
$lines[] = str_repeat('-', 72);

$expiring = [];
foreach ($chamas as $c) {
    $s = subscription_status($c);
    $expiry = $s['expires_at'] ? date('Y-m-d', strtotime((string)$s['expires_at'])) : 'never';
    $lines[] = sprintf(
        '%-28s %-9s fee %8s  expires %s  [%s]%s',
        str_cut($c['name'], 28),
        $c['plan'],
        number_format((float)$c['monthly_fee'], 2),
        $expiry,
        $s['state'],
        $c['status'] !== 'active' ? '  SUSPENDED(' . ($c['suspension_reason'] ?: 'manual') . ')' : ''
    );
    if (in_array($s['state'], ['expiring', 'grace'], true)) {
        $expiring[] = $c['name'] . ' (' . $expiry . ')';
    }
}

$lines[] = str_repeat('-', 72);
$lines[] = 'Needs attention: ' . ($expiring ? implode(', ', $expiring) : 'none');
$lines[] = 'Mode: ' . (mpesa_is_simulation() ? 'SIMULATION (no Daraja credentials)' : 'LIVE ' . strtoupper(MPESA_ENV));

echo implode("\n", $lines) . "\n";
