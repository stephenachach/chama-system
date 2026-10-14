<?php
require_once __DIR__ . '/../config/auth.php';
require_role(['admin']);
$id = (int)($_GET['id'] ?? 0);
try {
    $stmt = $pdo->prepare('DELETE FROM members WHERE id=? AND chama_id=?');
    $stmt->execute([$id, current_chama_id()]);
    if ($stmt->rowCount() === 0) {
        flash_error('Member not found.');
    } else {
        flash_success('Member deleted successfully.');
    }
} catch (PDOException $e) {
    flash_error('This member has records and cannot be deleted. You can mark the member inactive instead.');
}
header('Location: ' . url('members/index.php'));
exit;
