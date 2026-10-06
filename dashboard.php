<?php
$page_title = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$user = current_user();
$isManager = in_array($user['role'], ['admin', 'treasurer'], true);
$memberId = (int)($user['member_id'] ?? 0);

if ($isManager) {
    $totalMembers = $pdo->query("SELECT COUNT(*) FROM members WHERE status='active'")->fetchColumn();
    $totalContributions = $pdo->query('SELECT COALESCE(SUM(amount),0) FROM contributions')->fetchColumn();
    $loanBalance = $pdo->query("SELECT COALESCE(SUM(balance),0) FROM loans WHERE status='approved'")->fetchColumn();
    $totalWelfare = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM welfare_payouts WHERE status IN ('approved','paid')")->fetchColumn();
    $pendingLoans = $pdo->query("SELECT COUNT(*) FROM loans WHERE status='pending'")->fetchColumn();
    $pendingWelfare = $pdo->query("SELECT COUNT(*) FROM welfare_payouts WHERE status='pending'")->fetchColumn();
    $pendingRegistrations = $pdo->query("SELECT COUNT(*) FROM users WHERE account_status='pending'")->fetchColumn();
    $recentContributions = $pdo->query("SELECT c.*, m.full_name FROM contributions c JOIN members m ON m.id=c.member_id ORDER BY c.contribution_date DESC, c.id DESC LIMIT 5")->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM contributions WHERE member_id=?');
    $stmt->execute([$memberId]);
    $totalContributions = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM loans WHERE member_id=? AND status='approved'");
    $stmt->execute([$memberId]);
    $loanBalance = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE member_id=? AND status='pending'");
    $stmt->execute([$memberId]);
    $pendingLoans = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM welfare_payouts WHERE member_id=? AND status IN ('approved','paid')");
    $stmt->execute([$memberId]);
    $totalWelfare = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT c.*, m.full_name FROM contributions c JOIN members m ON m.id=c.member_id WHERE c.member_id=? ORDER BY c.contribution_date DESC, c.id DESC LIMIT 5");
    $stmt->execute([$memberId]);
    $recentContributions = $stmt->fetchAll();
}
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-4">
        <div>
            <h1 class="display-6 fw-bold mb-1">Welcome back, <?= e($user['name']) ?></h1>
            <p class="text-muted mb-3">A simple, clear view of your chama activity and financial status. Use the panels below to access the most important actions quickly.</p>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-primary">Role: <?= e(ucfirst($user['role'])) ?></span>
                <?php if ($isManager): ?><span class="badge bg-success">Group Manager</span><?php else: ?><span class="badge bg-secondary">Member</span><?php endif; ?>
            </div>
        </div>
        <?php if ($isManager): ?>
        <div class="d-grid gap-2 w-100 w-md-auto">
            <a class="btn btn-primary btn-lg" href="<?= url('members/create.php') ?>">New Member</a>
            <a class="btn btn-outline-primary btn-lg" href="<?= url('contributions/create.php') ?>">Record Contribution</a>
        </div>
        <?php else: ?>
        <div class="card bg-surface-alt border rounded-4 p-3 w-100 w-md-auto">
            <div class="text-muted small mb-2">Need help?</div>
            <p class="mb-1">You can apply for a loan and track your application status here.</p>
            <a class="btn btn-primary btn-sm mt-3" href="<?= url('loans/create.php') ?>">Apply for Loan</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="row row-cols-1 row-cols-md-4 g-3 mb-4">
    <?php if ($isManager): ?>
        <div class="col"><div class="card stat-card bg-primary"><div class="card-body"><div class="icon">👥</div><h6 class="mb-2">Active Members</h6><h2><?= e($totalMembers) ?></h2></div></div></div>
        <div class="col"><div class="card stat-card bg-success"><div class="card-body"><div class="icon">💰</div><h6 class="mb-2">Total Contributions</h6><h2><?= money($totalContributions) ?></h2></div></div></div>
        <div class="col"><div class="card stat-card bg-warning"><div class="card-body"><div class="icon">🏦</div><h6 class="mb-2">Loan Balance</h6><h2><?= money($loanBalance) ?></h2></div></div></div>
        <div class="col"><div class="card stat-card bg-danger"><div class="card-body"><div class="icon">🤝</div><h6 class="mb-2">Welfare Paid</h6><h2><?= money($totalWelfare) ?></h2></div></div></div>
    <?php else: ?>
        <div class="col"><div class="card stat-card bg-success"><div class="card-body"><div class="icon">💰</div><h6 class="mb-2">My Contributions</h6><h2><?= money($totalContributions) ?></h2></div></div></div>
        <div class="col"><div class="card stat-card bg-warning"><div class="card-body"><div class="icon">🏦</div><h6 class="mb-2">My Loan Balance</h6><h2><?= money($loanBalance) ?></h2></div></div></div>
        <div class="col"><div class="card stat-card bg-danger"><div class="card-body"><div class="icon">🤝</div><h6 class="mb-2">My Welfare Paid</h6><h2><?= money($totalWelfare) ?></h2></div></div></div>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><?= $isManager ? 'Recent Contributions' : 'My Recent Contributions' ?></span>
                <?php if ($isManager): ?><a href="<?= url('contributions/create.php') ?>" class="btn btn-sm btn-primary">Add Contribution</a><?php endif; ?>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Date</th><th>Member</th><th>Type</th><th>Amount</th><th>Reference</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentContributions as $row): ?>
                        <tr><td><?= e($row['contribution_date']) ?></td><td><?= e($row['full_name']) ?></td><td><?= e(ucfirst($row['type'])) ?></td><td><?= money($row['amount']) ?></td><td><?= e($row['reference']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$recentContributions): ?><tr><td colspan="5" class="text-center text-muted">No contributions recorded yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <?php if ($isManager): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h5 class="fw-bold">Pending Work</h5>
                    <div class="small text-muted mb-3">Keep an eye on approvals and pending requests.</div>
                    <p class="mb-1">Member registrations: <strong><?= e($pendingRegistrations) ?></strong></p>
                    <p class="mb-1">Loan applications: <strong><?= e($pendingLoans) ?></strong></p>
                    <p class="mb-0">Welfare requests: <strong><?= e($pendingWelfare) ?></strong></p>
                    <a class="btn btn-sm btn-outline-primary mt-3" href="<?= url('users/approvals.php') ?>">View Approvals</a>
                </div>
            </div>
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="fw-bold">Quick Actions</h5>
                    <div class="d-grid gap-2 mt-3">
                        <a class="btn btn-outline-primary" href="<?= url('members/create.php') ?>">Register Member</a>
                        <a class="btn btn-outline-success" href="<?= url('contributions/create.php') ?>">Record Contribution</a>
                        <a class="btn btn-outline-warning" href="<?= url('loans/create.php') ?>">Create Loan Application</a>
                        <a class="btn btn-outline-danger" href="<?= url('welfare/create.php') ?>">Record Welfare Request</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="fw-bold">Account Status</h5>
                    <p class="mb-0 text-muted">Your account is approved. To request loans or welfare support, contact your treasurer or admin.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
