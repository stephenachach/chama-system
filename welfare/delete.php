<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('welfare/index.php'));
    exit;
}
$id = (int)($_POST['id'] ?? 0);
$stmt = $pdo->prepare('DELETE FROM welfare_payouts WHERE id=? AND chama_id=?');
$stmt->execute([$id, current_chama_id()]);
if ($stmt->rowCount() === 0) {
    flash_error('Welfare request not found.');
} else {
    audit_log('welfare.delete', 'welfare', $id, 'Deleted welfare request');
    flash_success('Welfare request deleted successfully.');
}
header('Location: ' . url('welfare/index.php'));
exit;
