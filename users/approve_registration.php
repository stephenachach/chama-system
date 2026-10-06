<?php
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);

$id = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    flash_error('User registration not found.');
    header('Location: ' . url('users/approvals.php'));
    exit;
}

// Protect existing admin/treasurer accounts from being changed through this approval screen.
if (in_array($user['role'], ['admin', 'treasurer'], true) && ($user['account_status'] ?? 'approved') === 'approved') {
    flash_error('Protected staff account cannot be changed here.');
    header('Location: ' . url('users/approvals.php'));
    exit;
}

try {
    $pdo->beginTransaction();

    if ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE users SET account_status = 'approved', role = 'member' WHERE id = ?");
        $stmt->execute([$id]);

        if (!empty($user['member_id'])) {
            $stmt = $pdo->prepare("UPDATE members SET status = 'active' WHERE id = ?");
            $stmt->execute([$user['member_id']]);
        }

        flash_success('Member registration approved successfully. The member can now login.');
    } elseif ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE users SET account_status = 'rejected', role = 'member' WHERE id = ?");
        $stmt->execute([$id]);

        if (!empty($user['member_id'])) {
            $stmt = $pdo->prepare("UPDATE members SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$user['member_id']]);
        }

        flash_success('Member registration rejected.');
    } else {
        flash_error('Invalid approval action.');
    }

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash_error('Approval action failed. Please try again.');
}

header('Location: ' . url('users/approvals.php'));
exit;
