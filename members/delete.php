<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('members/index.php'));
    exit;
}
$id = (int)($_POST['id'] ?? 0);
try {
    $pre = $pdo->prepare('SELECT * FROM members WHERE id=? AND chama_id=?');
    $pre->execute([$id, current_chama_id()]);
    $gone = $pre->fetch();
    $stmt = $pdo->prepare('DELETE FROM members WHERE id=? AND chama_id=?');
    $stmt->execute([$id, current_chama_id()]);
    if ($gone) { audit_log('member.delete', 'member', $id, sprintf('Deleted member %s (%s)', $gone['full_name'], $gone['member_no'])); }
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
