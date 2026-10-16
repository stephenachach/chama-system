<?php
$page_title = 'Approve Loan';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT l.*, m.member_no, m.full_name FROM loans l JOIN members m ON m.id=l.member_id WHERE l.id=? AND l.chama_id=?');
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Approve Loan Application</h2>
            <div class="text-muted">Review loan details and make the approval decision.</div>
        </div>
        <a href="<?= url('loans/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Loans</a>
    </div>
</div>
<?php
$stmt->execute([$id, current_chama_id()]);
$loan = $stmt->fetch();
if (!$loan) { flash_error('Loan not found.'); header('Location: ' . url('loans/index.php')); exit; }
$eligibility = loanEligibility($loan);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE loans SET status='approved', approved_date=? WHERE id=?");
        $stmt->execute([date('Y-m-d'), $id]);
        audit_log('loan.approve', 'loan', $id, sprintf('Approved loan of KSh %s for %s', number_format((float)$loan['amount'], 2), $loan['full_name'] ?? 'member'));
        flash_success('Loan approved.');
    } elseif ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE loans SET status='rejected', approved_date=NULL WHERE id=?");
        $stmt->execute([$id]);
        audit_log('loan.reject', 'loan', $id, sprintf('Rejected loan of KSh %s for %s', number_format((float)$loan['amount'], 2), $loan['full_name'] ?? 'member'));
        flash_success('Loan rejected.');
    }
    header('Location: ' . url('loans/index.php')); exit;
}
?>
<div class="card shadow-sm mx-auto" style="max-width: 650px;">
    <div class="card-header">Approve / Reject Loan</div>
    <div class="card-body">
        <p><strong>Member:</strong> <?= e($loan['member_no'].' - '.$loan['full_name']) ?></p>
        <p><strong>Amount:</strong> <?= money($loan['amount']) ?> | <strong>Total Payable:</strong> <?= money($loan['total_payable']) ?></p>
        <p><strong>Purpose:</strong> <?= e($loan['purpose']) ?></p>
        <p><strong>Loan Eligibility:</strong> <span class="badge bg-<?= e($eligibility['class']) ?>"><?= e($eligibility['label']) ?></span></p>
        <?php if (!empty($eligibility['reasons'])): ?>
            <div class="alert alert-warning py-2">This loan needs review for: <strong><?= e(implode(', ', $eligibility['reasons'])) ?></strong></div>
        <?php endif; ?>
        <form method="post"><button class="btn btn-success" name="action" value="approve">Approve Loan</button> <button class="btn btn-danger" name="action" value="reject">Reject Loan</button> <a class="btn btn-secondary" href="<?= url('loans/index.php') ?>">Cancel</a></form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
