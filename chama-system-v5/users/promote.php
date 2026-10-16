<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash_error('Invalid user selected.');
    header('Location: ' . url('users/index.php'));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND chama_id = ? LIMIT 1');
$stmt->execute([$id, current_chama_id()]);
$user = $stmt->fetch();
if (!$user) {
    flash_error('User not found.');
    header('Location: ' . url('users/index.php'));
    exit;
}

if ($user['role'] !== 'member' || ($user['account_status'] ?? 'approved') !== 'approved') {
    flash_error('Only approved members can be promoted to treasurer.');
    header('Location: ' . url('users/index.php'));
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE users SET role = 'treasurer' WHERE id = ? AND chama_id = ?");
    $stmt->execute([$id, current_chama_id()]);
    audit_log('user.promote', 'user', $id, sprintf('Promoted %s to treasurer', $user['name'] ?? ('user #' . $id)));
    flash_success('Member promoted to treasurer successfully.');
} catch (PDOException $e) {
    flash_error('Could not promote user. Please try again.');
}

header('Location: ' . url('users/index.php'));
exit;
