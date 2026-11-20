<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin','treasurer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('welfare/index.php'));
    exit;
}
$id = (int)($_POST['id'] ?? 0);

// Block payment of large payouts until the second approval exists.
$stmt = $pdo->prepare('SELECT needs_second, second_ok FROM welfare_payouts WHERE id=? AND chama_id=?');
$stmt->execute([$id, current_chama_id()]);
$row = $stmt->fetch();
if ($row && (int)$row['needs_second'] === 1 && (int)$row['second_ok'] !== 1) {
    flash_error('This payout is at or above the group threshold and still needs a second approval from a different officer.');
    header('Location: ' . url('welfare/index.php'));
    exit;
}

$stmt = $pdo->prepare("UPDATE welfare_payouts SET status='paid' WHERE id=? AND chama_id=? AND status='approved'");
$stmt->execute([$id, current_chama_id()]);
if ($stmt->rowCount() === 0) {
    flash_error('Welfare request not found or not approved.');
} else {
    audit_log('welfare.pay', 'welfare', $id, 'Marked welfare payout as paid');
    flash_success('Welfare payout marked as paid.');
}
header('Location: ' . url('welfare/index.php'));
exit;
