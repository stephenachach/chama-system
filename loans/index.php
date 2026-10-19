<?php
$page_title = 'Loans';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer','member']);
require_once __DIR__ . '/../includes/header.php';
$user = current_user();
$isMember = $user['role'] === 'member';
$isAdmin = $user['role'] === 'admin';
$memberId = (int)($user['member_id'] ?? 0);
$status = $_GET['status'] ?? '';
$sql = "SELECT l.*, m.member_no, m.full_name, m.status AS member_status, COALESCE(SUM(r.amount),0) AS repaid FROM loans l JOIN members m ON m.id=l.member_id LEFT JOIN loan_repayments r ON r.loan_id=l.id";
$params = [];
$where = [];
$where[] = "l.chama_id=?";
$params[] = current_chama_id();
if ($status) {
    $where[] = "l.status=?";
    $params[] = $status;
}
if ($isMember) {
    $where[] = "l.member_id=?";
    $params[] = $memberId;
}
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " GROUP BY l.id ORDER BY l.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Loans</h2>
            <div class="text-muted"><?= $isMember ? 'Your loan applications and current statuses.' : 'Loan applications, approvals and repayments in one place.' ?></div>
        </div>
        <?php if ($isAdmin || $isMember): ?><a href="<?= url('loans/create.php') ?>" class="btn btn-primary btn-lg"><?= $isMember ? 'Apply for Loan' : 'New Loan' ?></a><?php endif; ?>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <form method="get" class="row g-2 mb-3 no-print">
            <div class="col-md-4"><select class="form-select" name="status"><option value="">All Statuses</option><?php foreach(['pending','approved','rejected','paid'] as $s): ?><option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-outline-primary">Filter</button>
                <?php if (!$isMember && $isAdmin): ?><a class="btn btn-outline-secondary" href="<?= url('loans/create.php') ?>">New Loan</a><?php endif; ?>
                <?php if ($isMember): ?><a class="btn btn-outline-secondary" href="<?= url('loans/create.php') ?>">Apply for Loan</a><?php endif; ?>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Member</th><th>Applied</th><th>Principal</th><th>Interest</th><th>Total</th><th>Repaid</th><th>Balance</th><th>Status</th><th>Eligibility</th><th width="230">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($loans as $l): ?>
                    <tr>
                        <td><?= e($l['member_no'].' - '.$l['full_name']) ?><br><small class="text-muted"><?= e($l['purpose']) ?></small></td><td><?= e($l['application_date']) ?></td><td><?= money($l['amount']) ?></td><td><?= e($l['interest_rate']) ?>%</td><td><?= money($l['total_payable']) ?></td><td><?= money($l['repaid']) ?></td><td><?= money($l['balance']) ?></td>
                        <td><span class="badge bg-<?= ['pending'=>'secondary','approved'=>'success','rejected'=>'danger','paid'=>'primary'][$l['status']] ?>"><?= e(ucfirst($l['status'])) ?></span></td>
                        <td><?= loanEligibilityBadge($l) ?></td>
                        <td>
                            <?php if (!$isMember): ?>
                                <?php if ($l['status']==='pending'): ?><a class="btn btn-sm btn-outline-success" href="<?= url('users/approvals.php?loan_id='.$l['id']) ?>">Approve/Reject</a><?php endif; ?>
                                <?php if ($l['status']==='approved'): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('loans/repayment.php?id='.$l['id']) ?>">Record</a><?php endif; ?>
                                <?php if ($isAdmin): ?><form method="post" action="<?= url('loans/delete.php') ?>" class="d-inline" onsubmit="return confirm('Delete this loan?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form><?php endif; ?>
                            <?php endif; ?>
                            <?php if (in_array($l['status'], ['approved','paid'], true) && (float)$l['balance'] > 0 && (!$isMember || (int)$l['member_id'] === $memberId)): ?>
                                <a class="btn btn-sm btn-success" href="<?= url('loans/repay.php?id='.$l['id']) ?>"><i class="fa-solid fa-mobile-screen me-1"></i>Pay M-Pesa</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$loans): ?><tr><td colspan="10" class="text-center text-muted">No loan records found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
