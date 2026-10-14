<?php
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("UPDATE welfare_payouts SET status='paid' WHERE id=? AND chama_id=? AND status='approved'");
$stmt->execute([$id, current_chama_id()]);
if ($stmt->rowCount() === 0) {
    flash_error('Welfare request not found or not approved.');
} else {
    flash_success('Welfare payout marked as paid.');
}
header('Location: ' . url('welfare/index.php'));
exit;
