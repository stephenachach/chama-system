<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin','treasurer']);

$id = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND chama_id = ? LIMIT 1');
$stmt->execute([$id, current_chama_id()]);
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

    $chamaId = current_chama_id();

    if ($action === 'approve') {
        $chamaRow = $pdo->prepare('SELECT * FROM chamas WHERE id = ? LIMIT 1');
        $chamaRow->execute([$chamaId]);
        $slot = plan_member_slot_available($chamaRow->fetch() ?: []);
        if (!$slot['ok']) {
            $pdo->rollBack();
            flash_error($slot['message']);
            header('Location: ' . url('users/approvals.php'));
            exit;
        }
        $stmt = $pdo->prepare("UPDATE users SET account_status = 'approved', role = 'member' WHERE id = ? AND chama_id = ?");
        $stmt->execute([$id, $chamaId]);

        if (!empty($user['member_id'])) {
            $stmt = $pdo->prepare("UPDATE members SET status = 'active' WHERE id = ? AND chama_id = ?");
            $stmt->execute([$user['member_id'], $chamaId]);
        }

        audit_log('user.approve', 'user', $id, sprintf('Approved registration of %s (%s)', $user['name'], $user['email']));
        flash_success('Member registration approved successfully. The member can now login.');
    } elseif ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE users SET account_status = 'rejected', role = 'member' WHERE id = ? AND chama_id = ?");
        $stmt->execute([$id, $chamaId]);

        if (!empty($user['member_id'])) {
            $stmt = $pdo->prepare("UPDATE members SET status = 'inactive' WHERE id = ? AND chama_id = ?");
            $stmt->execute([$user['member_id'], $chamaId]);
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
