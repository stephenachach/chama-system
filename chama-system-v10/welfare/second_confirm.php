<?php
/**
 * Second approval for large welfare payouts.
 * A payout at/above the group's threshold must be confirmed by a DIFFERENT
 * officer than the one who approved it (four-eyes principle).
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['admin', 'treasurer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('welfare/index.php'));
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$user = current_user();
$chamaId = current_chama_id();

$stmt = $pdo->prepare('SELECT * FROM welfare_payouts WHERE id = ? AND chama_id = ?');
$stmt->execute([$id, $chamaId]);
$w = $stmt->fetch();

if (!$w || $w['status'] !== 'approved' || (int)$w['needs_second'] !== 1) {
    flash_error('That payout does not need a second approval.');
} elseif ((int)$w['second_ok'] === 1) {
    flash_error('That payout was already second-approved.');
} elseif ((int)($w['approved_by'] ?? 0) === (int)$user['id']) {
    flash_error('You approved this payout yourself - a different officer must give the second approval.');
} else {
    $stmt = $pdo->prepare("UPDATE welfare_payouts SET second_ok = 1, second_by = ?, second_role = ?, second_at = NOW() WHERE id = ? AND chama_id = ?");
    $stmt->execute([(int)$user['id'], $user['role'], $id, $chamaId]);
    audit_log('welfare.second_approval', 'welfare', $id, sprintf('Second approval given for KSh %s payout', number_format((float)$w['amount'], 2)));
    flash_success('Second approval recorded. The payout can now be marked as paid.');
}

header('Location: ' . url('welfare/index.php'));
exit;
