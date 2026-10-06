<?php
$page_title = 'New Welfare Request';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';
$members = $pdo->query("SELECT id, member_no, full_name FROM members WHERE status='active' ORDER BY full_name")->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">New Welfare Request</h2>
            <div class="text-muted">Submit a welfare payout request with a clear reason.</div>
        </div>
        <a href="<?= url('welfare/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Welfare</a>
    </div>
</div>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = (int)($_POST['member_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $request_date = $_POST['request_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    if ($member_id && $amount > 0 && $reason) {
        $stmt = $pdo->prepare('INSERT INTO welfare_payouts (member_id, amount, reason, request_date, notes) VALUES (?,?,?,?,?)');
        $stmt->execute([$member_id, $amount, $reason, $request_date, $notes]);
        flash_success('Welfare request recorded successfully.');
        header('Location: ' . url('welfare/index.php')); exit;
    } else { flash_error('Please select member, amount and reason.'); }
}
?>
<div class="card shadow-sm mx-auto" style="max-width: 780px;">
    <div class="card-header">New Welfare Request</div>
    <div class="card-body"><form method="post" class="row g-3"><div class="col-12"><label class="form-label required">Member</label><select class="form-select" name="member_id" required><option value="">Select Member</option><?php foreach($members as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['member_no'].' - '.$m['full_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label required">Amount</label><input class="form-control" type="number" step="0.01" min="1" name="amount" required></div><div class="col-md-6"><label class="form-label required">Request Date</label><input class="form-control" type="date" name="request_date" value="<?= date('Y-m-d') ?>" required></div><div class="col-12"><label class="form-label required">Reason</label><input class="form-control" name="reason" placeholder="Medical, funeral support, emergency, etc." required></div><div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes"></textarea></div><div class="col-12"><button class="btn btn-primary">Save Request</button> <a class="btn btn-secondary" href="<?= url('welfare/index.php') ?>">Cancel</a></div></form></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
