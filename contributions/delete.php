<?php
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('DELETE FROM contributions WHERE id=?');
$stmt->execute([$id]);
flash_success('Contribution deleted successfully.');
header('Location: ' . url('contributions/index.php'));
exit;
