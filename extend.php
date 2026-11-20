<?php
/**
 * Grant extra time to a chama (trial extension, goodwill after an outage, etc).
 * No payment is recorded - it simply pushes the expiry date forward.
 * Superadmin only.
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['superadmin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('billing/index.php'));
    exit;
}

$chamaId = (int)($_POST['chama_id'] ?? 0);
$days = max(1, min(365, (int)($_POST['days'] ?? 30)));
$reason = trim((string)($_POST['reason'] ?? ''));

$stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ? LIMIT 1');
$stmt->execute([$chamaId]);
$chama = $stmt->fetch();
if (!$chama) {
    flash_error('Chama not found.');
    header('Location: ' . url('billing/index.php'));
    exit;
}

$newExpiry = grant_trial($chamaId, $days);

// Keep an auditable trace in the payment history (amount 0, method trial).
$stmt = $pdo->prepare(
    "INSERT INTO subscription_payments
        (chama_id, amount, months, period_start, period_end, method, status, notes, created_by, created_at, paid_at)
     VALUES (?, 0, 1, CURDATE(), ?, 'trial', 'paid', ?, ?, NOW(), NOW())"
);
$stmt->execute([
    $chamaId,
    $newExpiry,
    substr($reason !== '' ? $reason : ('Complimentary ' . $days . '-day extension'), 0, 250),
    (int)(current_user()['id'] ?? 0) ?: null,
]);

audit_log('billing.extend', 'subscription', $chamaId, sprintf('%s: granted %d free day(s); new expiry %s', $chama['name'], $days, date('d M Y', strtotime((string)$newExpiry))), ['reason' => $reason]);
flash_success($chama['name'] . ' extended by ' . $days . ' day' . ($days === 1 ? '' : 's') . '. New expiry: ' . date('d M Y', strtotime((string)$newExpiry)) . '.');
header('Location: ' . url('billing/index.php'));
exit;
