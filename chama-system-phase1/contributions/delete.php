<?php
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('DELETE FROM contributions WHERE id=? AND chama_id=?');
$stmt->execute([$id, current_chama_id()]);
if ($stmt->rowCount() === 0) {
    flash_error('Contribution not found.');
} else {
    flash_success('Contribution deleted successfully.');
}
header('Location: ' . url('contributions/index.php'));
exit;
