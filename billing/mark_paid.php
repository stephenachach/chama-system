<?php
/**
 * Record a subscription payment received outside M-Pesa (cash, bank, cheque).
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
$months = max(1, min(12, (int)($_POST['months'] ?? SUBSCRIPTION_BILLING_MONTHS)));
$method = in_array($_POST['method'] ?? '', ['manual', 'bank', 'cash'], true) ? $_POST['method'] : 'manual';
$notes = trim((string)($_POST['notes'] ?? ''));

$stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ? LIMIT 1');
$stmt->execute([$chamaId]);
$chama = $stmt->fetch();

if (!$chama) {
    flash_error('Chama not found.');
    header('Location: ' . url('billing/index.php'));
    exit;
}

$amount = (float)($_POST['amount'] ?? 0);
if ($amount <= 0) {
    // Default to the configured monthly fee for the chosen number of months.
    $amount = subscription_amount_due($chama) * $months;
}
if ($amount < 1) {
    flash_error('This chama has no monthly fee set, so nothing can be recorded. Set the fee first.');
    header('Location: ' . url('billing/index.php'));
    exit;
}

$paymentId = record_subscription_payment([
    'chama_id' => $chamaId,
    'amount' => $amount,
    'months' => $months,
    'method' => $method,
    'notes' => $notes !== '' ? $notes : 'Recorded manually by platform operator',
    'created_by' => (int)(current_user()['id'] ?? 0) ?: null,
]);

if ($paymentId) {
    $stmt = $pdo->prepare('SELECT plan_expires_at FROM chamas WHERE id = ?');
    $stmt->execute([$chamaId]);
    $newExpiry = (string)$stmt->fetchColumn();
    audit_log('billing.mark_paid', 'subscription', $paymentId, sprintf('%s: recorded %s offline payment (%s), plan to %s', $chama['name'], money($amount), $method, date('d M Y', strtotime($newExpiry))), ['chama_id' => $chamaId]);
    flash_success($chama['name'] . ': ' . money($amount) . ' recorded. Plan now runs to ' . date('d M Y', strtotime($newExpiry)) . '.');
} else {
    flash_error('Could not record the payment.');
}

header('Location: ' . url('billing/index.php'));
exit;
