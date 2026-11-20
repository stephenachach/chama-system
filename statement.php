<?php
/**
 * Member statement: on-screen preview plus a downloadable PDF.
 *
 * Managers can print any member of their group; a member can only print
 * their own. The PDF is generated in-house (includes/pdf.php) so it needs
 * no extra libraries on XAMPP.
 */
$page_title = 'Member Statement';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/pdf.php';   // also provides pdf_date()/pdf_money() for the HTML view
require_role(['admin', 'treasurer', 'member']);
$user = current_user();

$isManager = in_array($user['role'], ['admin', 'treasurer'], true);
$chamaId = current_chama_id();
$chama = current_chama();

$memberId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$isManager) {
    $memberId = (int)($user['member_id'] ?? 0);
}
if ($memberId <= 0 && $isManager) {
    $first = $pdo->prepare("SELECT id FROM members WHERE chama_id = ? ORDER BY full_name LIMIT 1");
    $first->execute([$chamaId]);
    $memberId = (int)$first->fetchColumn();
}

$member = null;
if ($memberId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? AND chama_id = ? LIMIT 1');
    $stmt->execute([$memberId, $chamaId]);
    $member = $stmt->fetch() ?: null;
}
if (!$member) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-warning">Member not found in this group.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$from = (string)($_GET['from'] ?? date('Y-01-01'));
$to = (string)($_GET['to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-01-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $to = date('Y-m-d'); }
if ($from > $to) { [$from, $to] = [$to, $from]; }

// ---- data ------------------------------------------------------------------
$stmt = $pdo->prepare('SELECT * FROM contributions WHERE member_id = ? AND chama_id = ? AND contribution_date BETWEEN ? AND ? ORDER BY contribution_date ASC, id ASC');
$stmt->execute([$memberId, $chamaId, $from, $to]);
$contributions = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM loans WHERE member_id = ? AND chama_id = ? ORDER BY application_date DESC, id DESC');
$stmt->execute([$memberId, $chamaId]);
$loans = $stmt->fetchAll();

$loanIds = array_column($loans, 'id');
$repayments = [];
if ($loanIds) {
    $in = implode(',', array_fill(0, count($loanIds), '?'));
    $stmt = $pdo->prepare("SELECT r.*, l.purpose AS loan_purpose FROM loan_repayments r JOIN loans l ON l.id = r.loan_id WHERE r.loan_id IN ($in) AND r.payment_date BETWEEN ? AND ? ORDER BY r.payment_date ASC, r.id ASC");
    $stmt->execute(array_merge($loanIds, [$from, $to]));
    $repayments = $stmt->fetchAll();
}

$totalContributed = 0.0;
$totalWelfare = 0.0;
foreach ($contributions as $c) {
    if (($c['type'] ?? '') === 'welfare') {
        $totalWelfare += (float)$c['amount'];
    } else {
        $totalContributed += (float)$c['amount'];
    }
}
$totalBorrowed = 0.0;
$totalOutstanding = 0.0;
foreach ($loans as $l) {
    $totalBorrowed += (float)$l['amount'];
    $totalOutstanding += (float)$l['balance'];
}
$totalRepaid = 0.0;
foreach ($repayments as $r) {
    $totalRepaid += (float)$r['amount'];
}

// ---- PDF -------------------------------------------------------------------
if (($_GET['format'] ?? '') === 'pdf') {
    require_once __DIR__ . '/../includes/pdf.php';
    $pdf = new SimplePdf('Member Statement', ($chama['name'] ?? 'Chama') . ' - ' . ($chama['phone'] ?? ''));
    $pdf->heading('Member Statement', ($member['full_name'] ?? '') . ' (' . ($member['member_no'] ?? '') . ')', [
        'Period' => pdf_date($from) . ' - ' . pdf_date($to),
        'Generated' => date('d M Y H:i'),
    ]);
    $pdf->kv('Member', $member['full_name'] ?? '');
    $pdf->kv('Member No.', $member['member_no'] ?? '');
    $pdf->kv('Phone', $member['phone'] ?? '');
    $pdf->kv('Group', ($chama['name'] ?? '') . ' - joined ' . pdf_date($member['join_date'] ?? ''));
    $pdf->kv('Status', ucfirst((string)($member['status'] ?? '')));

    $pdf->section('Contributions in period');
    $rows = [];
    foreach ($contributions as $c) {
        $rows[] = ['cells' => [
            pdf_date($c['contribution_date']),
            ucfirst((string)$c['type']),
            (string)($c['reference'] !== '' ? $c['reference'] : '-'),
            pdf_money($c['amount']),
        ]];
    }
    $rows[] = ['cells' => ['', '', 'Total', pdf_money($totalContributed + $totalWelfare)], 'bold' => true, 'fill' => [0.93, 0.95, 0.94]];
    $pdf->table([
        ['label' => 'Date', 'w' => 90],
        ['label' => 'Type', 'w' => 110],
        ['label' => 'Reference', 'w' => 190],
        ['label' => 'Amount', 'w' => 100, 'align' => 'right'],
    ], $rows, ['empty' => 'No contributions recorded in this period.']);

    $pdf->section('Loan repayments in period');
    $rows = [];
    foreach ($repayments as $r) {
        $rows[] = ['cells' => [
            pdf_date($r['payment_date']),
            (string)($r['loan_purpose'] ?? 'Loan'),
            (string)($r['reference'] !== '' ? $r['reference'] : '-'),
            pdf_money($r['amount']),
        ]];
    }
    $rows[] = ['cells' => ['', '', 'Total repaid', pdf_money($totalRepaid)], 'bold' => true, 'fill' => [0.93, 0.95, 0.94]];
    $pdf->table([
        ['label' => 'Date', 'w' => 90],
        ['label' => 'Loan', 'w' => 160],
        ['label' => 'Reference', 'w' => 140],
        ['label' => 'Amount', 'w' => 100, 'align' => 'right'],
    ], $rows, ['empty' => 'No loan repayments recorded in this period.']);

    $pdf->section('Loan position (all time)');
    $rows = [];
    foreach ($loans as $l) {
        $rows[] = ['cells' => [
            pdf_date($l['application_date']),
            (string)($l['purpose'] ?? ''),
            pdf_money($l['amount']),
            pdf_money($l['total_payable']),
            pdf_money((float)$l['total_payable'] - (float)$l['balance']),
            pdf_money($l['balance']),
            ucfirst((string)$l['status']),
        ]];
    }
    $pdf->table([
        ['label' => 'Taken', 'w' => 80],
        ['label' => 'Purpose', 'w' => 120],
        ['label' => 'Principal', 'w' => 80, 'align' => 'right'],
        ['label' => 'Payable', 'w' => 80, 'align' => 'right'],
        ['label' => 'Repaid', 'w' => 80, 'align' => 'right'],
        ['label' => 'Balance', 'w' => 80, 'align' => 'right'],
        ['label' => 'Status', 'w' => 60],
    ], $rows, ['empty' => 'This member has never taken a loan.']);

    $pdf->section('Summary');
    $items = [
        ['label' => 'Contributed in period', 'value' => pdf_money($totalContributed)],
    ];
    if ($totalWelfare > 0) {
        $items[] = ['label' => 'Welfare in period', 'value' => pdf_money($totalWelfare)];
    }
    $items[] = ['label' => 'Repaid in period', 'value' => pdf_money($totalRepaid)];
    $items[] = ['label' => 'Total borrowed (all time)', 'value' => pdf_money($totalBorrowed)];
    $items[] = ['label' => 'Outstanding balance', 'value' => pdf_money($totalOutstanding), 'emphasis' => true, 'color' => $totalOutstanding > 0 ? [0.65, 0.15, 0.10] : [0.10, 0.45, 0.25]];
    $pdf->summary($items);

    $pdf->gap(8);
    $pdf->paragraph('Generated automatically by the chama management system on ' . date('d M Y H:i') . '. Figures reflect every record entered up to that moment; contact your treasurer with any questions.', ['size' => 8.5, 'color' => [0.42, 0.45, 0.48]]);

    audit_log('statement.pdf', 'member', $memberId, sprintf('Downloaded PDF statement for %s (%s to %s)', $member['full_name'], $from, $to));
    $pdf->stream('statement-' . preg_replace('/[^A-Za-z0-9-]/', '-', $member['member_no'] ?? 'member') . '-' . $to . '.pdf');
}

// ---- HTML ------------------------------------------------------------------
require_once __DIR__ . '/../includes/header.php';

$memberOptions = [];
if ($isManager) {
    $stmt = $pdo->prepare("SELECT id, member_no, full_name FROM members WHERE chama_id = ? ORDER BY full_name");
    $stmt->execute([$chamaId]);
    $memberOptions = $stmt->fetchAll();
}
$pdfUrl = url('members/statement.php') . '?' . http_build_query(['id' => $memberId, 'from' => $from, 'to' => $to, 'format' => 'pdf']);
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Member Statement</h2>
            <div class="text-muted">Preview on screen, then download the PDF to share or print.</div>
        </div>
        <a href="<?= e($pdfUrl) ?>" class="btn btn-danger btn-lg"><i class="fa-solid fa-file-pdf me-2"></i>Download PDF</a>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <?php if ($isManager): ?>
            <div class="col-md-4">
                <label class="form-label">Member</label>
                <select name="id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($memberOptions as $opt): ?>
                    <option value="<?= (int)$opt['id'] ?>" <?= (int)$opt['id'] === $memberId ? 'selected' : '' ?>><?= e($opt['full_name'] . ' (' . $opt['member_no'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-3">
                <label class="form-label">From</label>
                <input type="date" name="from" value="<?= e($from) ?>" class="form-control">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">To</label>
                <input type="date" name="to" value="<?= e($to) ?>" class="form-control">
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Contributed (period)</div><h4 class="mb-0"><?= e(money($totalContributed)) ?></h4></div></div></div>
    <div class="col-6 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Repaid (period)</div><h4 class="mb-0"><?= e(money($totalRepaid)) ?></h4></div></div></div>
    <div class="col-6 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Borrowed (all time)</div><h4 class="mb-0"><?= e(money($totalBorrowed)) ?></h4></div></div></div>
    <div class="col-6 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Outstanding</div><h4 class="mb-0 <?= $totalOutstanding > 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($totalOutstanding)) ?></h4></div></div></div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between"><span>Contributions <?= e(pdf_date($from)) ?> - <?= e(pdf_date($to)) ?></span><span class="badge bg-secondary"><?= count($contributions) ?></span></div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php if (!$contributions): ?><tr><td colspan="4" class="text-muted">Nothing recorded in this period.</td></tr><?php endif; ?>
            <?php foreach ($contributions as $c): ?>
                <tr>
                    <td><?= e(pdf_date($c['contribution_date'])) ?></td>
                    <td><?= e(ucfirst((string)$c['type'])) ?><?= ($c['type'] ?? '') === 'mpesa' ? ' <span class="badge bg-success">M-Pesa</span>' : '' ?></td>
                    <td><code><?= e($c['reference'] !== '' ? $c['reference'] : '-') ?></code></td>
                    <td class="text-end fw-semibold"><?= e(money($c['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between"><span>Loans (all time)</span><span class="badge bg-secondary"><?= count($loans) ?></span></div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Taken</th><th>Purpose</th><th class="text-end">Principal</th><th class="text-end">Payable</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$loans): ?><tr><td colspan="6" class="text-muted">No loans.</td></tr><?php endif; ?>
            <?php foreach ($loans as $l): ?>
                <tr>
                    <td><?= e(pdf_date($l['application_date'])) ?></td>
                    <td><?= e($l['purpose']) ?></td>
                    <td class="text-end"><?= e(money($l['amount'])) ?></td>
                    <td class="text-end"><?= e(money($l['total_payable'])) ?></td>
                    <td class="text-end <?= (float)$l['balance'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= e(money($l['balance'])) ?></td>
                    <td><span class="badge bg-<?= $l['status'] === 'paid' ? 'success' : ($l['status'] === 'approved' ? 'info' : 'secondary') ?>"><?= e(ucfirst((string)$l['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
