<?php
$page_title = 'Reports';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$chamaId = current_chama_id();

$stmt = $pdo->prepare("SELECT m.member_no, m.full_name, COALESCE(SUM(c.amount),0) AS total FROM members m LEFT JOIN contributions c ON c.member_id=m.id AND c.contribution_date BETWEEN ? AND ? WHERE m.chama_id = ? GROUP BY m.id ORDER BY m.full_name");
$stmt->execute([$from, $to, $chamaId]);
$contributionReport = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT l.*, m.member_no, m.full_name, COALESCE(SUM(r.amount),0) AS repaid FROM loans l JOIN members m ON m.id=l.member_id LEFT JOIN loan_repayments r ON r.loan_id=l.id WHERE l.application_date BETWEEN ? AND ? AND l.chama_id = ? GROUP BY l.id ORDER BY l.id DESC");
$stmt->execute([$from, $to, $chamaId]);
$loanReport = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT w.*, m.member_no, m.full_name FROM welfare_payouts w JOIN members m ON m.id=w.member_id WHERE w.request_date BETWEEN ? AND ? AND w.chama_id = ? ORDER BY w.id DESC");
$stmt->execute([$from, $to, $chamaId]);
$welfareReport = $stmt->fetchAll();

$totalContributions = array_sum(array_column($contributionReport, 'total'));
$totalLoans = array_sum(array_column($loanReport, 'amount'));
$totalWelfare = array_sum(array_column($welfareReport, 'amount'));
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Financial Reports</h2>
            <div class="text-muted">Summaries for contributions, loans and welfare payouts.</div>
        </div>
        <button class="btn btn-outline-primary btn-lg no-print" onclick="window.print()">Print Report</button>
    </div>
</div>
<div class="hero-pad animate-slide-right">
    <div class="hero-pad-top">
        <span class="hero-pad-badge">System Overview</span>
        <span class="hero-pad-dot"></span>
    </div>

    <p class="hero-pad-intro">Progress bars show action areas</p>

    <div class="hero-level">
        <div class="hero-level-head">
            <span>Loan approvals</span>
            <strong>82%</strong>
        </div>
        <div class="hero-level-track">
            <span class="bar-blue" style="width: 82%;"></span>
        </div>
    </div>

    <div class="hero-level">
        <div class="hero-level-head">
            <span>Welfare payouts</span>
            <strong>64%</strong>
        </div>
        <div class="hero-level-track">
            <span class="bar-green" style="width: 64%;"></span>
        </div>
    </div>

    <div class="hero-level">
        <div class="hero-level-head">
            <span>Member contributions</span>
            <strong>48%</strong>
        </div>
        <div class="hero-level-track">
            <span class="bar-gold" style="width: 48%;"></span>
        </div>
    </div>

    <div class="hero-pad-foot">
        <span class="hero-pad-badge badge-soft">Welfare Community</span>
        <h3>Track Your Chama Progress</h3>
        <p>A clear snapshot of loan, welfare, and contribution flow.</p>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
