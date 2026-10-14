<?php
require_once __DIR__ . '/../config/auth.php';
require_role(['admin']);
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('DELETE FROM welfare_payouts WHERE id=? AND chama_id=?');
$stmt->execute([$id, current_chama_id()]);
if ($stmt->rowCount() === 0) {
    flash_error('Welfare request not found.');
} else {
    flash_success('Welfare request deleted successfully.');
}
header('Location: ' . url('welfare/index.php'));
exit;
