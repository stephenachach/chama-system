<?php
$page_title = 'Approve Welfare';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT w.*, m.member_no, m.full_name FROM welfare_payouts w JOIN members m ON m.id=w.member_id WHERE w.id=? AND w.chama_id=?');
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Approve Welfare Request</h2>
            <div class="text-muted">Review request details and choose whether to approve or reject.</div>
        </div>
        <a href="<?= url('welfare/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Welfare</a>
    </div>
</div>
<?php
$stmt->execute([$id, current_chama_id()]);
$welfare = $stmt->fetch();
if (!$welfare) { flash_error('Welfare request not found.'); header('Location: ' . url('welfare/index.php')); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE welfare_payouts SET status='approved', approved_date=? WHERE id=?");
        $stmt->execute([date('Y-m-d'), $id]);
        audit_log('welfare.approve', 'welfare', $id, sprintf('Approved welfare payout of KSh %s', number_format((float)$welfare['amount'], 2)));
        flash_success('Welfare request approved.');
    } elseif ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE welfare_payouts SET status='rejected', approved_date=NULL WHERE id=?");
        $stmt->execute([$id]);
        audit_log('welfare.reject', 'welfare', $id, sprintf('Rejected welfare payout of KSh %s', number_format((float)$welfare['amount'], 2)));
        flash_success('Welfare request rejected.');
    }
    header('Location: ' . url('welfare/index.php')); exit;
}
?>
<div class="card shadow-sm mx-auto" style="max-width: 650px;"><div class="card-header">Approve / Reject Welfare Request</div><div class="card-body"><p><strong>Member:</strong> <?= e($welfare['member_no'].' - '.$welfare['full_name']) ?></p><p><strong>Amount:</strong> <?= money($welfare['amount']) ?></p><p><strong>Reason:</strong> <?= e($welfare['reason']) ?></p><form method="post">
            <?= csrf_field() ?><button class="btn btn-success" name="action" value="approve">Approve</button> <button class="btn btn-danger" name="action" value="reject">Reject</button> <a class="btn btn-secondary" href="<?= url('welfare/index.php') ?>">Cancel</a></form></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
