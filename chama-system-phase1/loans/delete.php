<?php
require_once __DIR__ . '/../config/auth.php';
require_role(['admin']);
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('DELETE FROM loans WHERE id=? AND chama_id=?');
$stmt->execute([$id, current_chama_id()]);
if ($stmt->rowCount() === 0) {
    flash_error('Loan not found.');
} else {
    flash_success('Loan deleted successfully.');
}
header('Location: ' . url('loans/index.php'));
exit;
