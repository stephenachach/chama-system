<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin','treasurer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('users/approvals.php'));
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
if (!$id || !in_array($action, ['approve', 'reject'], true)) {
    flash_error('Invalid loan approval request.');
    header('Location: ' . url('users/approvals.php'));
    exit;
}

$stmt = $pdo->prepare('SELECT l.*, m.member_no, m.full_name FROM loans l JOIN members m ON m.id = l.member_id WHERE l.id = ? AND l.chama_id = ? LIMIT 1');
$stmt->execute([$id, current_chama_id()]);
$loan = $stmt->fetch();

if (!$loan || $loan['status'] !== 'pending') {
    flash_error('Loan not found or already processed.');
    header('Location: ' . url('users/approvals.php'));
    exit;
}

try {
    if ($action === 'approve') {
        $update = $pdo->prepare("UPDATE loans SET status = 'approved', approved_date = ? WHERE id = ?");
        $update->execute([date('Y-m-d'), $id]);
        audit_log('loan.approve', 'loan', $id, sprintf('Approved loan of KSh %s for %s', number_format((float)$loan['amount'], 2), $loan['full_name'] ?? 'member'));
        flash_success('Loan approved successfully.');
    } else {
        $update = $pdo->prepare("UPDATE loans SET status = 'rejected', approved_date = NULL WHERE id = ?");
        $update->execute([$id]);
        flash_success('Loan rejected successfully.');
    }
} catch (PDOException $e) {
    flash_error('Unable to process loan approval. Please try again.');
}

header('Location: ' . url('users/approvals.php'));
exit;
