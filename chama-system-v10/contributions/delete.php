<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin','treasurer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('contributions/index.php'));
    exit;
}
$id = (int)($_POST['id'] ?? 0);
$pre = $pdo->prepare('SELECT c.*, m.full_name FROM contributions c LEFT JOIN members m ON m.id = c.member_id WHERE c.id=? AND c.chama_id=?');
$pre->execute([$id, current_chama_id()]);
$gone = $pre->fetch();
$stmt = $pdo->prepare('DELETE FROM contributions WHERE id=? AND chama_id=?');
$stmt->execute([$id, current_chama_id()]);
if ($gone) {
    audit_log('contribution.delete', 'contribution', $id, sprintf('Deleted %s contribution of KSh %s (%s)', $gone['type'], number_format((float)$gone['amount'], 2), $gone['full_name'] ?? 'member'), ['reference' => $gone['reference']]);
}
if ($stmt->rowCount() === 0) {
    flash_error('Contribution not found.');
} else {
    flash_success('Contribution deleted successfully.');
}
header('Location: ' . url('contributions/index.php'));
exit;
