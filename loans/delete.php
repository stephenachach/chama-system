<?php
require_once __DIR__ . '/../config/auth.php';
require_role(['admin']);
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('DELETE FROM loans WHERE id=?');
$stmt->execute([$id]);
flash_success('Loan deleted successfully.');
header('Location: ' . url('loans/index.php'));
exit;
