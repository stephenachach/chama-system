<?php
$page_title = 'Reports';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$chamaId = current_chama_id();

// Contributions per member inside the date range.
$stmt = $pdo->prepare("SELECT m.id AS member_id, m.member_no, m.full_name, m.status AS member_status,
        COALESCE(SUM(c.amount),0) AS total, COUNT(c.id) AS payments
    FROM members m
    LEFT JOIN contributions c ON c.member_id = m.id AND c.contribution_date BETWEEN ? AND ?
    WHERE m.chama_id = ?
    GROUP BY m.id ORDER BY total DESC, m.full_name");
$stmt->execute([$from, $to, $chamaId]);
$contributionReport = $stmt->fetchAll();

// Loans applied for inside the date range, with what has been repaid.
$stmt = $pdo->prepare("SELECT l.*, m.member_no, m.full_name, COALESCE(SUM(r.amount),0) AS repaid
    FROM loans l JOIN members m ON m.id = l.member_id
    LEFT JOIN loan_repayments r ON r.loan_id = l.id
    WHERE l.application_date BETWEEN ? AND ? AND l.chama_id = ?
    GROUP BY l.id ORDER BY l.id DESC");
$stmt->execute([$from, $to, $chamaId]);
$loanReport = $stmt->fetchAll();

// Welfare requests inside the date range.
$stmt = $pdo->prepare("SELECT w.*, m.member_no, m.full_name
    FROM welfare_payouts w JOIN members m ON m.id = w.member_id
    WHERE w.request_date BETWEEN ? AND ? AND w.chama_id = ?
    ORDER BY w.id DESC");
$stmt->execute([$from, $to, $chamaId]);
$welfareReport = $stmt->fetchAll();

// Individual contribution entries (for the detailed list).
$stmt = $pdo->prepare("SELECT c.*, m.member_no, m.full_name
    FROM contributions c JOIN members m ON m.id = c.member_id
    WHERE c.contribution_date BETWEEN ? AND ? AND c.chama_id = ?
    ORDER BY c.contribution_date DESC, c.id DESC");
$stmt->execute([$from, $to, $chamaId]);
$contributionEntries = $stmt->fetchAll();

$totalContributions = array_sum(array_column($contributionReport, 'total'));
$totalLoans = array_sum(array_column($loanReport, 'amount'));
$totalRepaid = array_sum(array_column($loanReport, 'repaid'));
$totalWelfare = array_sum(array_column($welfareReport, 'amount'));
$welfarePaid = 0.0;
foreach ($welfareReport as $w) {
    if (in_array($w['status'], ['approved', 'paid'], true)) {
        $welfarePaid += (float)$w['amount'];
    }
}
$approvedLoans = 0.0;
$outstandingLoans = 0.0;
foreach ($loanReport as $l) {
    if ($l['status'] === 'approved' || $l['status'] === 'paid') {
        $approvedLoans += (float)$l['amount'];
        $outstandingLoans += (float)$l['balance'];
    }
}
$memberCount = count($contributionReport);
$payingMembers = 0;
foreach ($contributionReport as $row) {
    if ((float)$row['total'] > 0) {
        $payingMembers++;
    }
}
$participation = $memberCount > 0 ? (int)round(($payingMembers / $memberCount) * 100) : 0;
$netPosition = $totalContributions - $welfarePaid - $totalLoans + $totalRepaid;
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Financial Reports</h2>
            <div class="text-muted">
                <?= e($chama['name'] ?? '') ?> &middot;
                <?= e(date('d M Y', strtotime($from))) ?> to <?= e(date('d M Y', strtotime($to))) ?>
            </div>
        </div>
        <button class="btn btn-outline-primary btn-lg no-print" onclick="window.print()">Print Report</button>
    </div>
</div>

<form method="get" class="card shadow-sm mb-4 no-print">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">From</label>
                <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To</label>
                <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
            </div>
            <div class="col-md-6 d-flex gap-2 flex-wrap">
                <button class="btn btn-primary">Apply</button>
                <a class="btn btn-outline-secondary" href="<?= url('reports/index.php?from=' . date('Y-m-01') . '&to=' . date('Y-m-t')) ?>">This month</a>
                <a class="btn btn-outline-secondary" href="<?= url('reports/index.php?from=' . date('Y-01-01') . '&to=' . date('Y-m-d')) ?>">Year to date</a>
                <a class="btn btn-outline-secondary" href="<?= url('reports/index.php?from=' . date('Y-m-d', strtotime('-12 months')) . '&to=' . date('Y-m-d')) ?>">Last 12 months</a>
            </div>
        </div>
    </div>
</form>

<div class="row row-cols-1 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="card stat-card bg-primary"><div class="card-body"><div class="icon">💵</div><h6 class="mb-2">Contributions</h6><h2><?= money($totalContributions) ?></h2><div class="small opacity-75"><?= e(count($contributionEntries)) ?> payments</div></div></div></div>
    <div class="col"><div class="card stat-card bg-info"><div class="card-body"><div class="icon">🏦</div><h6 class="mb-2">Loans Disbursed</h6><h2><?= money($approvedLoans) ?></h2><div class="small opacity-75"><?= money($outstandingLoans) ?> outstanding</div></div></div></div>
    <div class="col"><div class="card stat-card bg-success"><div class="card-body"><div class="icon">🤝</div><h6 class="mb-2">Welfare Paid</h6><h2><?= money($welfarePaid) ?></h2><div class="small opacity-75"><?= money($totalWelfare) ?> requested</div></div></div></div>
    <div class="col"><div class="card stat-card bg-warning"><div class="card-body"><div class="icon">📊</div><h6 class="mb-2">Member Participation</h6><h2><?= e($participation) ?>%</h2><div class="small opacity-75"><?= e($payingMembers) ?> of <?= e($memberCount) ?> contributed</div></div></div></div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Position for the period</span>
        <span class="badge bg-<?= $netPosition >= 0 ? 'success' : 'danger' ?>"><?= money($netPosition) ?></span>
    </div>
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tbody>
                <tr><th style="width:60%">Contributions received</th><td class="text-end"><?= money($totalContributions) ?></td></tr>
                <tr><th>Loan repayments received</th><td class="text-end"><?= money($totalRepaid) ?></td></tr>
                <tr><th>Less: loans disbursed</th><td class="text-end text-danger">- <?= money($totalLoans) ?></td></tr>
                <tr><th>Less: welfare paid / approved</th><td class="text-end text-danger">- <?= money($welfarePaid) ?></td></tr>
                <tr class="table-light"><th>Net movement</th><td class="text-end"><strong><?= money($netPosition) ?></strong></td></tr>
            </tbody>
        </table>
        <div class="form-text mt-2">Cash held by the group also includes money from before this period. This table shows only movement inside the selected dates.</div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header">Contributions by member</div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Member No</th><th>Name</th><th class="text-end">Payments</th><th class="text-end">Total</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$contributionReport): ?>
                <tr><td colspan="5" class="text-muted p-3">No members in this group yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($contributionReport as $row): ?>
                <tr>
                    <td><?= e($row['member_no']) ?></td>
                    <td><?= e($row['full_name']) ?></td>
                    <td class="text-end"><?= e((int)$row['payments']) ?></td>
                    <td class="text-end"><strong><?= money($row['total']) ?></strong></td>
                    <td>
                        <?php if ((float)$row['total'] <= 0): ?>
                            <span class="badge bg-danger">No contribution in period</span>
                        <?php elseif ($row['member_status'] !== 'active'): ?>
                            <span class="badge bg-secondary">Inactive member</span>
                        <?php else: ?>
                            <span class="badge bg-success">Up to date</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr><th colspan="3">Total</th><th class="text-end"><?= money($totalContributions) ?></th><th></th></tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header">Loans</div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Applied</th><th>Member</th><th class="text-end">Amount</th><th class="text-end">Interest</th><th class="text-end">Payable</th><th class="text-end">Repaid</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$loanReport): ?>
                <tr><td colspan="8" class="text-muted p-3">No loan applications in this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($loanReport as $l):
                $badge = ['approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger', 'paid' => 'info'][$l['status']] ?? 'secondary';
            ?>
                <tr>
                    <td><?= e(date('d M Y', strtotime((string)$l['application_date']))) ?></td>
                    <td><?= e($l['full_name']) ?><br><small class="text-muted"><?= e($l['member_no']) ?></small></td>
                    <td class="text-end"><?= money($l['amount']) ?></td>
                    <td class="text-end"><?= e(number_format((float)$l['interest_rate'], 2)) ?>%</td>
                    <td class="text-end"><?= money($l['total_payable']) ?></td>
                    <td class="text-end"><?= money($l['repaid']) ?></td>
                    <td class="text-end"><strong><?= money($l['balance']) ?></strong></td>
                    <td><span class="badge bg-<?= e($badge) ?>"><?= e(ucfirst($l['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr><th colspan="2">Totals</th><th class="text-end"><?= money($totalLoans) ?></th><th></th><th></th><th class="text-end"><?= money($totalRepaid) ?></th><th class="text-end"><?= money($outstandingLoans) ?></th><th></th></tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header">Welfare payouts</div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Requested</th><th>Member</th><th>Reason</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$welfareReport): ?>
                <tr><td colspan="5" class="text-muted p-3">No welfare requests in this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($welfareReport as $w):
                $badge = ['approved' => 'success', 'paid' => 'info', 'pending' => 'warning', 'rejected' => 'danger'][$w['status']] ?? 'secondary';
            ?>
                <tr>
                    <td><?= e(date('d M Y', strtotime((string)$w['request_date']))) ?></td>
                    <td><?= e($w['full_name']) ?></td>
                    <td><?= e($w['reason']) ?></td>
                    <td class="text-end"><?= money($w['amount']) ?></td>
                    <td><span class="badge bg-<?= e($badge) ?>"><?= e(ucfirst($w['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr><th colspan="3">Total requested</th><th class="text-end"><?= money($totalWelfare) ?></th><th><?= money($welfarePaid) ?> approved/paid</th></tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header">Contribution entries</div>
    <div class="card-body table-responsive p-0">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Member</th><th>Type</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php if (!$contributionEntries): ?>
                <tr><td colspan="5" class="text-muted p-3">No contributions recorded in this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($contributionEntries as $c): ?>
                <tr>
                    <td><?= e(date('d M Y', strtotime((string)$c['contribution_date']))) ?></td>
                    <td><?= e($c['full_name']) ?> <small class="text-muted">(<?= e($c['member_no']) ?>)</small></td>
                    <td><?= e(ucfirst((string)$c['type'])) ?></td>
                    <td><code><?= e($c['reference'] ?: '-') ?></code></td>
                    <td class="text-end"><?= money($c['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
