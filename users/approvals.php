<?php
$page_title = 'Pending Approvals';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';

$selectedLoanId = (int)($_GET['loan_id'] ?? 0);
$chamaId = current_chama_id();

$stmt = $pdo->prepare("SELECT u.id AS user_id, u.name, u.email, u.role, u.account_status, u.created_at, m.id AS member_id, m.member_no, m.full_name, m.phone, m.address, m.join_date, m.status AS member_status FROM users u LEFT JOIN members m ON m.id = u.member_id WHERE u.account_status = 'pending' AND u.chama_id = ? ORDER BY u.created_at ASC");
$stmt->execute([$chamaId]);
$pendingUsers = $stmt->fetchAll();

$loanStmt = $pdo->prepare("SELECT l.id AS loan_id, l.member_id, l.amount, l.interest_rate, l.total_payable, l.balance, l.purpose, l.application_date, m.member_no, m.full_name FROM loans l JOIN members m ON m.id = l.member_id WHERE l.status = 'pending' AND l.chama_id = ? ORDER BY l.application_date ASC");
$loanStmt->execute([$chamaId]);
$pendingLoans = $loanStmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE account_status = 'approved' AND chama_id = ?");
$stmt->execute([$chamaId]);
$approvedCount = $stmt->fetchColumn();
$pendingCount = count($pendingUsers);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE account_status = 'rejected' AND chama_id = ?");
$stmt->execute([$chamaId]);
$rejectedCount = $stmt->fetchColumn();
$pendingLoanCount = count($pendingLoans);
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Pending Member Approvals</h2>
            <div class="text-muted">Approve or reject new member self-registrations quickly and safely.</div>
        </div>
        <div class="d-flex flex-column gap-2 text-end">
            <span class="badge bg-warning">Pending members: <?= e($pendingCount) ?></span>
            <span class="badge bg-warning">Pending loans: <?= e($pendingLoanCount) ?></span>
            <span class="badge bg-success">Approved: <?= e($approvedCount) ?></span>
            <span class="badge bg-danger">Rejected: <?= e($rejectedCount) ?></span>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><h6>Pending Members</h6><h3><?= e($pendingCount) ?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><h6>Pending Loans</h6><h3><?= e($pendingLoanCount) ?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><h6>Approved Accounts</h6><h3><?= e($approvedCount) ?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><h6>Rejected</h6><h3><?= e($rejectedCount) ?></h3></div></div></div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header">New Registrations Waiting Approval</div>
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Member No</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>Registered</th>
                    <th width="220">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pendingUsers as $u): ?>
                <tr>
                    <td><?= e($u['member_no']) ?></td>
                    <td><?= e($u['full_name'] ?: $u['name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e($u['phone']) ?></td>
                    <td><?= e($u['address']) ?></td>
                    <td><?= e($u['created_at']) ?></td>
                    <td>
                        <a class="btn btn-sm btn-success" onclick="return confirm('Approve this registration?')" href="<?= url('users/approve_registration.php?id=' . $u['user_id'] . '&action=approve') ?>">Approve</a>
                        <a class="btn btn-sm btn-danger" onclick="return confirm('Reject this registration?')" href="<?= url('users/approve_registration.php?id=' . $u['user_id'] . '&action=reject') ?>">Reject</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pendingUsers): ?>
                <tr><td colspan="7" class="text-center text-muted">No pending registrations.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header">Loan Applications Waiting Approval</div>
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Amount</th>
                    <th>Purpose</th>
                    <th>Applied</th>
                    <th>Status</th>
                    <th width="220">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pendingLoans as $loan): ?>
                <tr id="loan-<?= e($loan['loan_id']) ?>" class="<?= $selectedLoanId === $loan['loan_id'] ? 'table-warning' : '' ?>">
                    <td><?= e($loan['member_no'].' - '.$loan['full_name']) ?></td>
                    <td><?= money($loan['amount']) ?></td>
                    <td><?= e($loan['purpose']) ?></td>
                    <td><?= e($loan['application_date']) ?></td>
                    <td><span class="badge bg-secondary">Pending</span></td>
                    <td>
                        <a class="btn btn-sm btn-outline-success" href="<?= url('users/approve_loan.php?id=' . $loan['loan_id'] . '&action=approve') ?>">Approve</a>
                        <a class="btn btn-sm btn-outline-danger" href="<?= url('users/approve_loan.php?id=' . $loan['loan_id'] . '&action=reject') ?>">Reject</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pendingLoans): ?>
                <tr><td colspan="6" class="text-center text-muted">No pending loan applications.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
