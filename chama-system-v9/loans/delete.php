<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin']);
$id = (int)($_GET['id'] ?? 0);
$pre = $pdo->prepare('SELECT l.*, m.full_name FROM loans l LEFT JOIN members m ON m.id = l.member_id WHERE l.id=? AND l.chama_id=?');
$pre->execute([$id, current_chama_id()]);
$gone = $pre->fetch();
$stmt = $pdo->prepare('DELETE FROM loans WHERE id=? AND chama_id=?');
$stmt->execute([$id, current_chama_id()]);
if ($gone) {
    audit_log('loan.delete', 'loan', $id, sprintf('Deleted loan of KSh %s (%s) with balance KSh %s', number_format((float)$gone['amount'], 2), $gone['full_name'] ?? 'member', number_format((float)$gone['balance'], 2)), ['status' => $gone['status']]);
}
if ($stmt->rowCount() === 0) {
    flash_error('Loan not found.');
} else {
    flash_success('Loan deleted successfully.');
}
header('Location: ' . url('loans/index.php'));
exit;
