<?php
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);

$id = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';
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
