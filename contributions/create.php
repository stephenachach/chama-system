<?php
$page_title = 'Record Contribution';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';
$members = $pdo->query("SELECT id, member_no, full_name FROM members WHERE status='active' ORDER BY full_name")->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Record Contribution</h2>
            <div class="text-muted">Enter contribution details clearly and quickly.</div>
        </div>
        <a href="<?= url('contributions/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Contributions</a>
    </div>
</div>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = (int)($_POST['member_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $date = $_POST['contribution_date'] ?? date('Y-m-d');
    $type = $_POST['type'] ?? 'monthly';
    $reference = trim($_POST['reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if ($member_id && $amount > 0 && $date) {
        $stmt = $pdo->prepare('INSERT INTO contributions (member_id, amount, contribution_date, type, reference, notes) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$member_id, $amount, $date, $type, $reference, $notes]);
        flash_success('Contribution recorded successfully.');
        header('Location: ' . url('contributions/index.php')); exit;
    } else { flash_error('Please select member, enter amount and date.'); }
}
?>
<div class="card shadow-sm mx-auto" style="max-width: 780px;">
    <div class="card-header">Record Contribution</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-12"><label class="form-label required">Member</label><select class="form-select" name="member_id" required><option value="">Select Member</option><?php foreach($members as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['member_no'].' - '.$m['full_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label required">Amount</label><input class="form-control" type="number" step="0.01" min="1" name="amount" required></div>
            <div class="col-md-6"><label class="form-label required">Date</label><input class="form-control" type="date" name="contribution_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-6"><label class="form-label">Type</label><select class="form-select" name="type"><option value="monthly">Monthly</option><option value="registration">Registration</option><option value="welfare">Welfare</option><option value="other">Other</option></select></div>
            <div class="col-md-6"><label class="form-label">Reference</label><input class="form-control" name="reference" placeholder="Receipt/Mpesa code"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes"></textarea></div>
            <div class="col-12"><button class="btn btn-primary">Save Contribution</button> <a class="btn btn-secondary" href="<?= url('contributions/index.php') ?>">Cancel</a></div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
