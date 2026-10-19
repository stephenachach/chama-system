<?php
$page_title = 'Dashboard';
require_once __DIR__ . '/config/auth.php';
require_login();
$user = current_user();

if (is_superadmin()) {
    // ------------------------------------------------------------------
    // Platform operator view
    // ------------------------------------------------------------------
    $totalChamas = $pdo->query('SELECT COUNT(*) FROM chamas')->fetchColumn();
    $activeChamas = $pdo->query("SELECT COUNT(*) FROM chamas WHERE status = 'active'")->fetchColumn();
    $totalMembers = $pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
    $platformRevenue = $pdo->query("SELECT COALESCE(SUM(monthly_fee),0) FROM chamas WHERE monthly_fee > 0")->fetchColumn();
    $collectedThisMonth = subscription_revenue(date('Y-m-01'), date('Y-m-t'));

    $recentChamas = $pdo->query("
        SELECT c.*, (SELECT COUNT(*) FROM members m WHERE m.chama_id = c.id) AS member_count
        FROM chamas c ORDER BY c.id DESC LIMIT 5
    ")->fetchAll();

    // Chamas that need billing attention: expired, in grace, expiring soon,
    // or already suspended for non-payment.
    $attentionChamas = [];
    foreach ($pdo->query("SELECT * FROM chamas WHERE monthly_fee > 0")->fetchAll() as $row) {
        $st = subscription_status($row);
        if (in_array($st['state'], ['expired', 'grace', 'expiring'], true)
            || ($row['status'] !== 'active' && ($row['suspension_reason'] ?? '') === 'nonpayment')) {
            $row['_sub'] = $st;
            $attentionChamas[] = $row;
        }
    }
    usort($attentionChamas, function ($a, $b) {
        return strcmp((string)($a['plan_expires_at'] ?? '9999'), (string)($b['plan_expires_at'] ?? '9999'));
    });

    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="card hero-card shadow-sm mb-4">
        <div class="card-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-4">
            <div>
                <h1 class="display-6 fw-bold mb-1">Platform Dashboard</h1>
                <p class="text-muted mb-0">You are signed in as the platform operator. Create chamas, bill their subscriptions by M-Pesa, and track group activity across the network.</p>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-success btn-lg" href="<?= url('billing/index.php') ?>">Billing</a>
                <a class="btn btn-primary btn-lg" href="<?= url('chamas/create.php') ?>">New Chama</a>
            </div>
        </div>
    </div>

    <div class="row row-cols-1 row-cols-md-4 g-3 mb-4">
        <div class="col"><div class="card stat-card bg-primary"><div class="card-body"><div class="icon">🏘️</div><h6 class="mb-2">Total Chamas</h6><h2><?= e($totalChamas) ?></h2></div></div></div>
        <div class="col"><div class="card stat-card bg-success"><div class="card-body"><div class="icon">💰</div><h6 class="mb-2">Collected This Month</h6><h2><?= money($collectedThisMonth) ?></h2></div></div></div>
        <div class="col"><div class="card stat-card bg-info"><div class="card-body"><div class="icon">📈</div><h6 class="mb-2">Recurring / Month</h6><h2><?= money($platformRevenue) ?></h2></div></div></div>
        <div class="col"><div class="card stat-card bg-warning"><div class="card-body"><div class="icon">👥</div><h6 class="mb-2">Total Members</h6><h2><?= e($totalMembers) ?></h2></div></div></div>
    </div>

    <?php if ($attentionChamas): ?>
    <div class="card shadow-sm mb-4 border-warning">
        <div class="card-header bg-warning-subtle d-flex justify-content-between align-items-center">
            <span><strong>Billing attention needed</strong> (<?= count($attentionChamas) ?>)</span>
            <a href="<?= url('billing/index.php') ?>" class="btn btn-sm btn-outline-dark">Open Billing</a>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>Chama</th><th>Fee</th><th>Expires at</th><th>State</th><th width="200">Action</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($attentionChamas, 0, 8) as $c): ?>
                    <tr>
                        <td><strong><?= e($c['name']) ?></strong></td>
                        <td><?= money($c['monthly_fee']) ?></td>
                        <td><?= e($c['plan_expires_at'] ? date('d M Y', strtotime((string)$c['plan_expires_at'])) : '-') ?></td>
                        <td><span class="badge bg-<?= e($c['_sub']['class']) ?>"><?= e($c['_sub']['label']) ?></span>
                            <?php if ($c['status'] !== 'active'): ?><span class="badge bg-danger ms-1">Suspended</span><?php endif; ?></td>
                        <td>
                            <a class="btn btn-sm btn-success" href="<?= url('billing/pay.php?chama_id=' . (int)$c['id']) ?>">Bill M-Pesa</a>
                            <form method="post" action="<?= url('billing/extend.php') ?>" class="d-inline">
            <?= csrf_field() ?>
                                <input type="hidden" name="chama_id" value="<?= (int)$c['id'] ?>">
                                <input type="hidden" name="days" value="30">
                                <button class="btn btn-sm btn-outline-secondary" title="Grant 30 days">+30d</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Latest Chamas</span>
            <a href="<?= url('chamas/index.php') ?>" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Name</th><th>Members</th><th>Plan</th><th>Monthly Fee</th><th>Expires at</th><th>Status</th><th width="90">Action</th></tr></thead>
                <tbody>
                <?php foreach ($recentChamas as $c): ?>
                    <tr>
                        <td><?= e($c['name']) ?></td>
                        <td><?= e($c['member_count']) ?></td>
                        <td><span class="badge bg-<?= $c['plan'] === 'pro' ? 'primary' : 'secondary' ?>"><?= e(ucfirst($c['plan'])) ?></span></td>
                        <td><?= money($c['monthly_fee']) ?></td>
                        <td><?= $c['plan_expires_at'] ? e(date('d M Y', strtotime((string)$c['plan_expires_at']))) : '<span class="text-muted">No expiry</span>' ?></td>
                        <td><span class="badge bg-<?= $c['status'] === 'active' ? 'success' : 'danger' ?>"><?= e(ucfirst($c['status'])) ?></span></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="<?= url('chamas/view.php?id=' . $c['id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentChamas): ?><tr><td colspan="7" class="text-center text-muted">No chamas yet. Create your first one.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php require_once __DIR__ . '/includes/footer.php'; exit;
}

require_once __DIR__ . '/includes/header.php';
$isManager = in_array($user['role'], ['admin', 'treasurer'], true);
$memberId = (int)($user['member_id'] ?? 0);
$chamaId = current_chama_id();
$chama = $chama ?? current_chama();

if ($isManager) {
    // ------------------------------------------------------------------
    // Committee dashboard: this month first, then everything that needs
    // a decision, then lifetime totals. Mobile-first card layout.
    // ------------------------------------------------------------------
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t 23:59:59');

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM contributions WHERE chama_id = ? AND contribution_date BETWEEN ? AND ?');
    $stmt->execute([$chamaId, $monthStart, $monthEnd]);
    $monthContrib = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT member_id) FROM contributions WHERE chama_id = ? AND contribution_date BETWEEN ? AND ?');
    $stmt->execute([$chamaId, $monthStart, $monthEnd]);
    $paidMembersThisMonth = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id, full_name, phone FROM members WHERE chama_id = ? AND status = 'active' ORDER BY full_name");
    $stmt->execute([$chamaId]);
    $activeMembers = $stmt->fetchAll();
    $totalMembers = count($activeMembers);

    $stmt = $pdo->prepare('SELECT DISTINCT member_id FROM contributions WHERE chama_id = ? AND contribution_date BETWEEN ? AND ?');
    $stmt->execute([$chamaId, $monthStart, $monthEnd]);
    $paidIds = array_map('intval', array_column($stmt->fetchAll(), 'member_id'));
    $followUps = array_values(array_filter($activeMembers, fn($m) => !in_array((int)$m['id'], $paidIds, true)));

    $monthlyDues = (float)($chama['monthly_dues'] ?? 0);
    $expectedMonth = $monthlyDues > 0 ? $monthlyDues * $totalMembers : 0.0;
    $monthPct = $expectedMonth > 0 ? max(0, min(100, (int)round($monthContrib / $expectedMonth * 100))) : 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM welfare_payouts WHERE status='pending' AND chama_id = ?");
    $stmt->execute([$chamaId]);
    $pendingWelfare = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE status='pending' AND chama_id = ?");
    $stmt->execute([$chamaId]);
    $pendingLoans = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE account_status='pending' AND chama_id = ?");
    $stmt->execute([$chamaId]);
    $pendingRegistrations = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT mp.*, m.full_name FROM mpesa_payments mp LEFT JOIN members m ON m.id = mp.member_id
                            WHERE mp.chama_id = ? AND mp.status = 'pending' ORDER BY mp.id DESC LIMIT 5");
    $stmt->execute([$chamaId]);
    $pendingPayments = $stmt->fetchAll();
    $pendingPaymentsCount = count($pendingPayments);

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM contributions WHERE chama_id = ?');
    $stmt->execute([$chamaId]);
    $totalContributions = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM loans WHERE status='approved' AND chama_id = ?");
    $stmt->execute([$chamaId]);
    $loanBalance = (float)$stmt->fetchColumn();

    // "Welfare Paid" counts only money that actually went out (status = paid).
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM welfare_payouts WHERE status='paid' AND chama_id = ?");
    $stmt->execute([$chamaId]);
    $welfarePaid = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT c.*, m.full_name FROM contributions c JOIN members m ON m.id=c.member_id WHERE c.chama_id = ? ORDER BY c.contribution_date DESC, c.id DESC LIMIT 5');
    $stmt->execute([$chamaId]);
    $recentContributions = $stmt->fetchAll();

    $hour = (int)date('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $dashSub = subscription_status($chama ?: []);
    ?>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h1 class="h3 fw-bold mb-1"><?= e($greeting) ?>, <?= e($user['name']) ?></h1>
            <div class="text-muted"><?= e($chama['name'] ?? '') ?> &middot; <?= e(date('l j F Y')) ?></div>
        </div>
        <?php if (!empty($dashSub['expires_at'])): ?>
        <a href="<?= url('account/subscription.php') ?>" class="btn btn-sm btn-outline-<?= e($dashSub['class'] === 'success' ? 'secondary' : $dashSub['class']) ?>">
            <i class="fa-solid fa-calendar-check me-1"></i>Plan paid to <?= e(date('d M Y', strtotime((string)$dashSub['expires_at']))) ?>
        </a>
        <?php endif; ?>
    </div>

    <!-- Obvious primary actions -->
    <div class="row g-2 mb-4">
        <div class="col-12 col-md-4 d-grid">
            <a class="btn btn-primary btn-lg py-3" href="<?= url('contributions/create.php') ?>">
                <i class="fa-solid fa-money-bill-wave me-1"></i>Record a payment
            </a>
        </div>
        <div class="col-12 col-md-4 d-grid">
            <a class="btn btn-outline-danger btn-lg py-3 position-relative" href="<?= url('welfare/index.php') ?>">
                <i class="fa-solid fa-heart me-1"></i>Review welfare
                <?php if ($pendingWelfare > 0): ?><span class="badge rounded-pill bg-danger position-absolute top-0 start-100 translate-middle"><?= $pendingWelfare ?></span><?php endif; ?>
            </a>
        </div>
        <div class="col-12 col-md-4 d-grid">
            <a class="btn btn-outline-primary btn-lg py-3" href="<?= url('members/create.php') ?>">
                <i class="fa-solid fa-user-plus me-1"></i>Add / invite member
            </a>
        </div>
    </div>

    <!-- This month -->
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="text-muted small text-uppercase fw-semibold">Contributions this month</div>
                    <div class="display-6 fw-bold"><?= money($monthContrib) ?></div>
                </div>
                <div class="text-md-end">
                    <div class="text-muted small"><?= $paidMembersThisMonth ?> of <?= $totalMembers ?> active members have paid</div>
                    <?php if ($expectedMonth > 0): ?>
                    <div class="text-muted small">Target <?= money($expectedMonth) ?> (<?= money($monthlyDues) ?> &times; <?= $totalMembers ?>)</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($expectedMonth > 0): ?>
            <div class="progress mt-2" style="height: 10px;" role="progressbar" aria-valuenow="<?= $monthPct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Monthly collection progress">
                <div class="progress-bar bg-success" style="width: <?= $monthPct ?>%"></div>
            </div>
            <div class="text-muted small mt-1"><?= $monthPct ?>% of this month&rsquo;s target collected</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Needs a decision -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3">
            <a href="<?= url('welfare/index.php') ?>" class="text-decoration-none">
                <div class="card h-100 shadow-sm <?= $pendingWelfare ? 'border-danger' : '' ?>">
                    <div class="card-body text-center py-3">
                        <div class="fs-3 fw-bold <?= $pendingWelfare ? 'text-danger' : 'text-muted' ?>"><?= $pendingWelfare ?></div>
                        <div class="small text-muted">Welfare requests to review</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="<?= url('users/approvals.php') ?>" class="text-decoration-none">
                <div class="card h-100 shadow-sm <?= $pendingLoans ? 'border-warning' : '' ?>">
                    <div class="card-body text-center py-3">
                        <div class="fs-3 fw-bold <?= $pendingLoans ? 'text-warning' : 'text-muted' ?>"><?= $pendingLoans ?></div>
                        <div class="small text-muted">Loan applications to decide</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="<?= url('users/approvals.php') ?>" class="text-decoration-none">
                <div class="card h-100 shadow-sm <?= $pendingRegistrations ? 'border-info' : '' ?>">
                    <div class="card-body text-center py-3">
                        <div class="fs-3 fw-bold <?= $pendingRegistrations ? 'text-info' : 'text-muted' ?>"><?= $pendingRegistrations ?></div>
                        <div class="small text-muted">Member sign-ups to approve</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="<?= url('contributions/pay.php') ?>" class="text-decoration-none">
                <div class="card h-100 shadow-sm <?= $pendingPaymentsCount ? 'border-primary' : '' ?>">
                    <div class="card-body text-center py-3">
                        <div class="fs-3 fw-bold <?= $pendingPaymentsCount ? 'text-primary' : 'text-muted' ?>"><?= $pendingPaymentsCount ?></div>
                        <div class="small text-muted">M-Pesa payments awaiting confirmation</div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <?php if ($pendingPayments): ?>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">Awaiting confirmation</div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($pendingPayments as $pp): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <div>
                            <div class="fw-semibold"><?= e($pp['full_name'] ?? 'Member') ?></div>
                            <div class="small text-muted"><?= e(ucfirst($pp['purpose'])) ?> &middot; started <?= e($pp['created_at']) ?></div>
                        </div>
                        <div class="fw-bold text-nowrap"><?= money($pp['amount']) ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="card-footer small text-muted">A payment completes only when Safaricom confirms it. You can also record cash/bank payments manually.</div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-lg-<?= $pendingPayments ? '6' : '12' ?>">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Members needing follow-up this month</span>
                    <span class="badge bg-secondary"><?= count($followUps) ?></span>
                </div>
                <?php if ($followUps): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach (array_slice($followUps, 0, 6) as $fm): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <div>
                            <div class="fw-semibold"><?= e($fm['full_name']) ?></div>
                            <div class="small text-muted"><?= e($fm['phone']) ?></div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary text-nowrap" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $fm['phone'])) ?>"><i class="fa-solid fa-phone me-1"></i>Call</a>
                    </li>
                    <?php endforeach; ?>
                    <?php if (count($followUps) > 6): ?><li class="list-group-item small text-muted">+ <?= count($followUps) - 6 ?> more &mdash; see Members</li><?php endif; ?>
                </ul>
                <?php else: ?>
                <div class="card-body text-muted">Everyone has contributed this month. &#127881;</div>
                <?php endif; ?>
                <div class="card-footer"><a href="<?= url('members/index.php') ?>" class="btn btn-sm btn-outline-primary">Open members</a></div>
            </div>
        </div>
    </div>

    <!-- Lifetime totals -->
    <div class="row row-cols-2 row-cols-lg-4 g-2 mb-3">
        <div class="col"><div class="card shadow-sm h-100"><div class="card-body text-center py-3"><div class="text-muted small">Active members</div><div class="fs-4 fw-bold"><?= $totalMembers ?></div></div></div></div>
        <div class="col"><div class="card shadow-sm h-100"><div class="card-body text-center py-3"><div class="text-muted small">Total contributions</div><div class="fs-4 fw-bold"><?= money($totalContributions) ?></div></div></div></div>
        <div class="col"><div class="card shadow-sm h-100"><div class="card-body text-center py-3"><div class="text-muted small">Loans outstanding</div><div class="fs-4 fw-bold"><?= money($loanBalance) ?></div></div></div></div>
        <div class="col"><div class="card shadow-sm h-100"><div class="card-body text-center py-3"><div class="text-muted small">Welfare actually paid</div><div class="fs-4 fw-bold"><?= money($welfarePaid) ?></div></div></div></div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Recent contributions</span>
            <a href="<?= url('contributions/index.php') ?>" class="btn btn-sm btn-outline-primary">View all</a>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Date</th><th>Member</th><th>Type</th><th class="text-end">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($recentContributions as $row): ?>
                    <tr><td><?= e($row['contribution_date']) ?></td><td><?= e($row['full_name']) ?></td><td><?= e(ucfirst($row['type'])) ?></td><td class="text-end fw-semibold"><?= money($row['amount']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$recentContributions): ?><tr><td colspan="4" class="text-center text-muted py-4">No contributions recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ----------------------------------------------------------------------
// Member home screen: dues & due date, payment status, welfare status,
// loan status, statement - one clean phone-first screen.
// ----------------------------------------------------------------------
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t 23:59:59');
$monthLabel = date('F Y');
$dueBy = date('j M Y', strtotime(date('Y-m-t')));
$monthlyDues = (float)($chama['monthly_dues'] ?? 0);

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0), MAX(contribution_date) FROM contributions WHERE member_id=? AND contribution_date BETWEEN ? AND ?');
$stmt->execute([$memberId, $monthStart, $monthEnd]);
[$monthPaid, $monthLastDate] = array_pad((array)$stmt->fetch(PDO::FETCH_NUM), 2, null);
$monthPaid = (float)$monthPaid;

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM contributions WHERE member_id=?');
$stmt->execute([$memberId]);
$totalContributions = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0), COUNT(*) FROM loans WHERE member_id=? AND status='approved' AND balance > 0");
$stmt->execute([$memberId]);
[$loanBalance, $activeLoans] = array_pad((array)$stmt->fetch(PDO::FETCH_NUM), 2, null);
$loanBalance = (float)$loanBalance;
$activeLoans = (int)$activeLoans;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE member_id=? AND status='pending'");
$stmt->execute([$memberId]);
$pendingLoans = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT * FROM welfare_payouts WHERE member_id=? ORDER BY request_date DESC, id DESC LIMIT 1');
$stmt->execute([$memberId]);
$myWelfare = $stmt->fetch() ?: null;

$stmt = $pdo->prepare('SELECT c.* FROM contributions c WHERE c.member_id=? ORDER BY c.contribution_date DESC, c.id DESC LIMIT 5');
$stmt->execute([$memberId]);
$recentContributions = $stmt->fetchAll();

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

if ($monthlyDues > 0 && $monthPaid >= $monthlyDues) {
    $duesState = 'paid';
} elseif ($monthPaid > 0) {
    $duesState = 'part';
} else {
    $duesState = 'unpaid';
}
$welfareBadges = ['pending' => 'secondary', 'approved' => 'info', 'rejected' => 'danger', 'paid' => 'success'];
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h3 fw-bold mb-1"><?= e($greeting) ?>, <?= e($user['name']) ?></h1>
        <div class="text-muted"><?= e($chama['name'] ?? '') ?></div>
    </div>
    <a class="btn btn-outline-secondary" href="<?= url('members/statement.php') ?>"><i class="fa-solid fa-file-pdf me-1"></i>My statement</a>
</div>

<!-- Dues & payment status -->
<div class="card shadow-sm mb-3 <?= $duesState === 'paid' ? 'border-success' : ($monthlyDues > 0 ? 'border-warning' : '') ?>">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="text-muted small text-uppercase fw-semibold">This month (<?= e($monthLabel) ?>)</div>
                <?php if ($monthlyDues > 0): ?>
                    <?php if ($duesState === 'paid'): ?>
                        <div class="fs-4 fw-bold text-success"><i class="fa-solid fa-circle-check me-1"></i>Dues paid</div>
                        <div class="text-muted small">You paid <?= money($monthPaid) ?><?= $monthLastDate ? ' on ' . e(date('d M Y', strtotime((string)$monthLastDate))) : '' ?>. Thank you!</div>
                    <?php elseif ($duesState === 'part'): ?>
                        <div class="fs-4 fw-bold text-warning">Part paid: <?= money($monthPaid) ?> of <?= money($monthlyDues) ?></div>
                        <div class="text-muted small">Remaining <?= money($monthlyDues - $monthPaid) ?> &middot; due by <?= e($dueBy) ?></div>
                    <?php else: ?>
                        <div class="fs-4 fw-bold">Dues not paid yet</div>
                        <div class="text-muted small"><?= money($monthlyDues) ?> for <?= e($monthLabel) ?> &middot; due by <?= e($dueBy) ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="fs-4 fw-bold">No monthly dues set</div>
                    <div class="text-muted small">Your group has not set a monthly amount.</div>
                <?php endif; ?>
            </div>
            <div class="text-md-end">
                <div class="text-muted small">Paid to date</div>
                <div class="fs-4 fw-bold"><?= money($totalContributions) ?></div>
            </div>
        </div>
        <a class="btn btn-success btn-lg w-100 mt-3 py-3" href="<?= url('contributions/pay.php') ?>">
            <i class="fa-solid fa-mobile-screen-button me-1"></i>Pay with M-Pesa
        </a>
    </div>
</div>

<!-- Loan & welfare status -->
<div class="row g-2 mb-3">
    <div class="col-12 col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1"><i class="fa-solid fa-hand-holding-dollar me-1"></i>My loans</div>
                <?php if ($activeLoans > 0): ?>
                    <div class="fs-5 fw-bold"><?= money($loanBalance) ?> remaining</div>
                    <div class="text-muted small"><?= $activeLoans ?> active loan<?= $activeLoans > 1 ? 's' : '' ?> &middot; repay from the Pay page</div>
                <?php elseif ($pendingLoans > 0): ?>
                    <div class="fs-5 fw-bold">Application in review</div>
                    <div class="text-muted small"><?= $pendingLoans ?> pending &middot; the committee will decide soon</div>
                <?php else: ?>
                    <div class="fs-5 fw-bold text-muted">No active loan</div>
                    <div class="text-muted small">You can apply from the Loans page</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-1"><i class="fa-solid fa-heart me-1"></i>My welfare</div>
                <?php if ($myWelfare): ?>
                    <div class="fs-5 fw-bold">
                        <?= money($myWelfare['amount']) ?>
                        <span class="badge bg-<?= e($welfareBadges[$myWelfare['status']] ?? 'secondary') ?> align-middle ms-1"><?= e(ucfirst($myWelfare['status'])) ?></span>
                    </div>
                    <div class="text-muted small">Last request: <?= e($myWelfare['request_date']) ?> &middot; <?= e($myWelfare['reason'] !== '' && $myWelfare['reason'] !== null ? $myWelfare['reason'] : 'no reason given') ?></div>
                <?php else: ?>
                    <div class="fs-5 fw-bold text-muted">No welfare requests</div>
                    <div class="text-muted small">Ask your treasurer how to request support</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent activity -->
<div class="card shadow-sm mb-3">
    <div class="card-header">My recent payments</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($recentContributions as $row): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-semibold"><?= money($row['amount']) ?> <span class="fw-normal text-muted small">&middot; <?= e(ucfirst($row['type'])) ?></span></div>
                <div class="small text-muted"><?= e(date('d M Y', strtotime((string)$row['contribution_date']))) ?><?= $row['reference'] ? ' &middot; ref ' . e($row['reference']) : '' ?></div>
            </div>
            <i class="fa-solid fa-circle-check text-success"></i>
        </li>
        <?php endforeach; ?>
        <?php if (!$recentContributions): ?><li class="list-group-item text-center text-muted py-4">No payments recorded yet.</li><?php endif; ?>
    </ul>
</div>

<div class="alert alert-secondary small">
    <i class="fa-solid fa-circle-info me-1"></i>Something wrong with your records? Contact your group&rsquo;s treasurer or admin - they manage the books for <?= e($chama['name'] ?? 'your group') ?>.
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
