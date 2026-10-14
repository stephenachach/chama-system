<?php
$page_title = 'Loan Repayment';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT l.*, m.member_no, m.full_name FROM loans l JOIN members m ON m.id=l.member_id WHERE l.id=? AND l.chama_id=?');
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Loan Repayment</h2>
            <div class="text-muted">Record a payment and monitor the loan balance.</div>
        </div>
        <a href="<?= url('loans/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Loans</a>
    </div>
</div>
<?php
$stmt->execute([$id, current_chama_id()]);
$loan = $stmt->fetch();
if (!$loan) { flash_error('Loan not found.'); header('Location: ' . url('loans/index.php')); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    $date = $_POST['payment_date'] ?? date('Y-m-d');
    $reference = trim($_POST['reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if ($amount > 0 && $amount <= (float)$loan['balance']) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO loan_repayments (loan_id, amount, payment_date, reference, notes) VALUES (?,?,?,?,?)');
        $stmt->execute([$id, $amount, $date, $reference, $notes]);
        $newBalance = max(0, (float)$loan['balance'] - $amount);
        $newStatus = $newBalance <= 0 ? 'paid' : 'approved';
        $stmt = $pdo->prepare('UPDATE loans SET balance=?, status=? WHERE id=?');
        $stmt->execute([$newBalance, $newStatus, $id]);
        $pdo->commit();
        flash_success('Loan repayment recorded successfully.');
        header('Location: ' . url('loans/index.php')); exit;
    } else { flash_error('Enter an amount greater than zero and not more than the balance.'); }
}
$repayments = $pdo->prepare('SELECT * FROM loan_repayments WHERE loan_id=? ORDER BY payment_date DESC, id DESC');
$repayments->execute([$id]);
$repayments = $repayments->fetchAll();
?>
<div class="row g-3">
    <div class="col-lg-5"><div class="card shadow-sm"><div class="card-header">Loan Details</div><div class="card-body"><p><strong>Member:</strong> <?= e($loan['member_no'].' - '.$loan['full_name']) ?></p><p><strong>Total Payable:</strong> <?= money($loan['total_payable']) ?></p><p><strong>Balance:</strong> <?= money($loan['balance']) ?></p><p><strong>Status:</strong> <?= e(ucfirst($loan['status'])) ?></p></div></div></div>
    <div class="col-lg-7"><div class="card shadow-sm"><div class="card-header">Record Repayment</div><div class="card-body"><form method="post" class="row g-3"><div class="col-md-6"><label class="form-label required">Amount</label><input class="form-control" type="number" step="0.01" min="1" max="<?= e($loan['balance']) ?>" name="amount" required></div><div class="col-md-6"><label class="form-label required">Payment Date</label><input class="form-control" type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required></div><div class="col-md-6"><label class="form-label">Reference</label><input class="form-control" name="reference"></div><div class="col-md-6"><label class="form-label">Notes</label><input class="form-control" name="notes"></div><div class="col-12"><button class="btn btn-primary">Save Repayment</button> <a class="btn btn-secondary" href="<?= url('loans/index.php') ?>">Back</a></div></form></div></div></div>
</div>
<div class="card shadow-sm mt-3"><div class="card-header">Repayment History</div><div class="card-body table-responsive"><table class="table"><thead><tr><th>Date</th><th>Amount</th><th>Reference</th><th>Notes</th></tr></thead><tbody><?php foreach($repayments as $r): ?><tr><td><?= e($r['payment_date']) ?></td><td><?= money($r['amount']) ?></td><td><?= e($r['reference']) ?></td><td><?= e($r['notes']) ?></td></tr><?php endforeach; ?><?php if(!$repayments): ?><tr><td colspan="4" class="text-center text-muted">No repayments yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
