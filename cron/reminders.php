<?php
define('CSRF_EXEMPT', true);   // public form / machine endpoint: CSRF handled per-flow
/**
 * Monthly dues reminder job.
 *
 * For every active chama with SMS reminders switched on, this finds members
 * who have not yet paid this month's dues and sends each ONE reminder per
 * month (the date is stamped on the chama row, so re-running the job on the
 * same day - or every day - never spams anyone).
 *
 *   CLI:   php cron/reminders.php            (run daily from cron/Task Scheduler)
 *   Cron:  0 7 * * * php /path/to/chama_system/cron/reminders.php
 *   URL:   cron/reminders.php?token=<MPESA_CALLBACK_TOKEN>
 *
 * In simulation mode the messages are only logged (SMS > Log), so you can
 * schedule this safely before buying any SMS credit.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/sms.php';

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

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthLabel = date('F Y');

$stmt = $pdo->prepare(
    "SELECT * FROM chamas
      WHERE status = 'active' AND sms_enabled = 1
        AND (sms_last_reminder IS NULL OR sms_last_reminder < ?)
        AND sms_reminder_day <= ?
      ORDER BY id"
);
$stmt->execute([$monthStart, (int)date('j')]);
$chamas = $stmt->fetchAll();

$out = [];
$totalSent = 0;
$totalFailed = 0;

foreach ($chamas as $chama) {
    $chamaId = (int)$chama['id'];

    // Skip groups whose subscription has lapsed: they cannot log in anyway,
    // and we should not spend SMS credit on a suspended tenant.
    if (!empty($chama['plan_expires_at']) && $chama['plan_expires_at'] < $today) {
        $out[] = sprintf('chama #%d %s: skipped (subscription expired %s)', $chamaId, $chama['name'], $chama['plan_expires_at']);
        continue;
    }

    $stmt = $pdo->prepare("SELECT id, full_name, phone FROM members WHERE chama_id = ? AND status = 'active' ORDER BY full_name");
    $stmt->execute([$chamaId]);
    $members = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT DISTINCT member_id FROM contributions WHERE chama_id = ? AND contribution_date >= ? AND type <> 'welfare'");
    $stmt->execute([$chamaId, $monthStart]);
    $paid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $unpaid = array_values(array_filter($members, fn($m) => !in_array((int)$m['id'], $paid, true)));
    if (!$unpaid) {
        $pdo->prepare('UPDATE chamas SET sms_last_reminder = ? WHERE id = ?')->execute([$today, $chamaId]);
        $out[] = sprintf('chama #%d %s: everyone paid for %s - no reminders needed', $chamaId, $chama['name'], $monthLabel);
        continue;
    }

    $sent = 0;
    $failed = 0;
    foreach ($unpaid as $m) {
        $first = strtok($m['full_name'], ' ') ?: $m['full_name'];
        $dues = (float)($chama['monthly_dues'] ?? 0);
        $msg = sprintf(
            'Dear %s, your %s dues of KSh %s for %s are not yet recorded. Pay by M-Pesa (Pay Dues in the app) or see your treasurer. Asante!',
            $first,
            $monthLabel,
            number_format($dues, 2),
            $chama['name']
        );
        $res = sms_send([
            'chama_id'  => $chamaId,
            'member_id' => (int)$m['id'],
            'phone'     => (string)$m['phone'],
            'purpose'   => 'reminder',
            'message'   => $msg,
        ]);
        $res['ok'] ? $sent++ : $failed++;
    }
    $totalSent += $sent;
    $totalFailed += $failed;
    $pdo->prepare('UPDATE chamas SET sms_last_reminder = ? WHERE id = ?')->execute([$today, $chamaId]);
    $out[] = sprintf('chama #%d %s: %d reminder(s) queued, %d failed', $chamaId, $chama['name'], $sent, $failed);
}

if (!$out) {
    $out[] = 'No chama has SMS reminders enabled (Chamas > Edit > SMS settings). Nothing to do.';
}
$out[] = sprintf('Done. %d sent, %d failed on %s.', $totalSent, $totalFailed, $today);

echo implode("\n", $out) . "\n";
