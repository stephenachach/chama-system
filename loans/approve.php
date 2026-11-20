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
$chamaSettings = $pdo->query('SELECT * FROM chamas WHERE id = ' . (int)current_chama_id())->fetch() ?: [];
$capInfo = loan_limit_for_member($chamaSettings, (int)$loan['member_id']);
$capViolation = loan_limit_violation($chamaSettings, (int)$loan['member_id'], (float)$loan['amount']);
$isOfficer = in_array(current_user()['role'] ?? '', ['admin','treasurer'], true);
$awaitingSecond = (int)($loan['needs_second'] ?? 0) === 1 && (int)($loan['second_ok'] ?? 0) !== 1 && (int)($loan['approved_by'] ?? 0) !== 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user = current_user();
    $chamaRow = $pdo->query('SELECT * FROM chamas WHERE id = ' . (int)current_chama_id())->fetch() ?: [];
    if ($action === 'approve') {
        $limitError = loan_limit_violation($chamaRow, (int)$loan['member_id'], (float)$loan['amount']);
        $override = ((int)($_POST['override_limit'] ?? 0)) === 1 && in_array($user['role'] ?? '', ['admin','treasurer'], true);
        if ($limitError !== null && !$override) {
            flash_error($limitError);
            header('Location: ' . url('loans/index.php')); exit;
        }
        $threshold = (float)($chamaRow['loan_approval_threshold'] ?? 0);
        $needsSecond = $threshold > 0 && (float)$loan['amount'] >= $threshold;

        if (!$needsSecond) {
            $stmt = $pdo->prepare("UPDATE loans SET status='approved', approved_date=?, approved_by=?, needs_second=0 WHERE id=? AND chama_id=?");
            $stmt->execute([date('Y-m-d'), (int)$user['id'], $id, current_chama_id()]);
            audit_log('loan.approve', 'loan', $id, sprintf('Approved loan of KSh %s for %s', number_format((float)$loan['amount'], 2), $loan['full_name'] ?? 'member'), ['single_officer' => true] + ($override ? ['override_limit' => true] : []));
            flash_success('Loan approved.');
        } elseif ((int)($loan['approved_by'] ?? 0) === (int)$user['id']) {
            flash_error('You recorded the first approval. A different officer must give the second approval before this loan is approved.');
        } elseif ((int)($loan['second_ok'] ?? 0) === 1) {
            flash_error('That loan has already been fully approved.');
        } elseif ((int)($loan['approved_by'] ?? 0) === 0) {
            $stmt = $pdo->prepare("UPDATE loans SET approved_by=?, needs_second=1 WHERE id=? AND chama_id=?");
            $stmt->execute([(int)$user['id'], $id, current_chama_id()]);
            audit_log('loan.first_approval', 'loan', $id, sprintf('First approval given for KSh %s loan (%s)', number_format((float)$loan['amount'], 2), $loan['full_name'] ?? 'member'), ($override ? ['override_limit' => true] : []));
            flash_success('First approval saved. One more officer must confirm before it becomes approved.');
        } else {
            $stmt = $pdo->prepare("UPDATE loans SET status='approved', approved_date=?, second_ok=1, second_by=?, second_at=NOW() WHERE id=? AND chama_id=? AND approved_by <> ?");
            $stmt->execute([date('Y-m-d'), (int)$user['id'], $id, current_chama_id(), (int)$user['id']]);
            audit_log('loan.second_approval', 'loan', $id, sprintf('Second approval completed for KSh %s loan (%s)', number_format((float)$loan['amount'], 2), $loan['full_name'] ?? 'member'));
            flash_success('Second approval recorded. The loan is now approved.');
        }
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
        <p><strong>Loan Eligibility:</strong> <span class="badge bg-<?= e($eligibility['class']) ?>"><?= e($eligibility['label']) ?></span>
        <?php if ((float)($chamaSettings['loan_cap_multiplier'] ?? 0) > 0): ?> | <strong>Group cap:</strong> <?= e(rtrim(rtrim(number_format((float)$chamaSettings['loan_cap_multiplier'],2),'0'),'.')) ?>x savings<?php if (!empty($chamaSettings['loan_cap_max'])): ?>, ceiling <?= money($chamaSettings['loan_cap_max']) ?><?php endif; ?> (max <?= money($capInfo['allowed']) ?> now)<?php endif; ?></p>
        <?php if (!empty($eligibility['reasons'])): ?>
            <div class="alert alert-warning py-2">This loan needs review for: <strong><?= e(implode(', ', $eligibility['reasons'])) ?></strong></div>
        <?php endif; ?>
        <?php if ($capViolation !== null): ?>
            <div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= e($capViolation) ?></div>
        <?php endif; ?>
        <?php if ($awaitingSecond): ?>
            <div class="alert alert-info py-2">First approval is on record. This loan still needs confirmation by a <strong>different officer</strong> before it is approved.</div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <?php if ($capViolation !== null && $isOfficer): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="override_limit" value="1" id="ovl"><label class="form-check-label" for="ovl">Override cap as an officer (recorded in the audit trail)</label></div><?php endif; ?>
            <?php
            $approveLabel = 'Approve Loan';
            $approveDisabled = false;
            $thr = (float)($chamaSettings['loan_approval_threshold'] ?? 0);
            if ($thr > 0 && (float)$loan['amount'] >= $thr) {
                if ((int)($loan['approved_by'] ?? 0) === (int)current_user()['id']) { $approveDisabled = true; $approveLabel = 'Waiting for a different officer'; }
                elseif ((int)($loan['approved_by'] ?? 0) !== 0) { $approveLabel = 'Give second approval & approve'; }
                else { $approveLabel = 'Record first approval'; }
            }
            ?>
            <button class="btn btn-success" name="action" value="approve" <?= $approveDisabled ? 'disabled' : '' ?>><?= e($approveLabel) ?></button>
            <button class="btn btn-danger" name="action" value="reject">Reject Loan</button>
            <a class="btn btn-secondary" href="<?= url('loans/index.php') ?>">Cancel</a>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
