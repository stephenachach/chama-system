<?php
$page_title = 'New Loan Application';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin','member']);
require_once __DIR__ . '/../includes/header.php';
$user = current_user();
$isMember = $user['role'] === 'member';
$stmt = $pdo->prepare("SELECT id, member_no, full_name FROM members WHERE chama_id = ? AND status='active' ORDER BY full_name");
$stmt->execute([current_chama_id()]);
$members = $stmt->fetchAll();
$headerTitle = $isMember ? 'Apply for Loan' : 'New Loan Application';
$headerDesc = $isMember ? 'Submit a loan request for review by the treasurer.' : 'Create a loan application with clear repayment terms.';
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1"><?= e($headerTitle) ?></h2>
            <div class="text-muted"><?= e($headerDesc) ?></div>
        </div>
        <a href="<?= url('loans/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Loans</a>
    </div>
</div>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isMember) {
        $member_id = (int)($user['member_id'] ?? 0);
    } else {
        $member_id = (int)($_POST['member_id'] ?? 0);
    }
    $amount = (float)($_POST['amount'] ?? 0);
    $interest = (float)($_POST['interest_rate'] ?? 0);
    $date = $_POST['application_date'] ?? date('Y-m-d');
    $purpose = trim($_POST['purpose'] ?? '');
    $total = $amount + ($amount * $interest / 100);
    if ($member_id && $amount > 0 && $date) {
        $chamaId = current_chama_id();
        // Confirm the member belongs to this group.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM members WHERE id = ? AND chama_id = ?');
        $stmt->execute([$member_id, $chamaId]);
        if ((int)$stmt->fetchColumn() === 0) {
            flash_error('That member does not belong to this group.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO loans (chama_id, member_id, amount, interest_rate, total_payable, balance, purpose, application_date) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$chamaId, $member_id, $amount, $interest, $total, $total, $purpose, $date]);
            audit_log('loan.create', 'loan', (int)$pdo->lastInsertId(), sprintf('Loan application of KSh %s at %s%% interest', number_format($amount, 2), $interest), ['purpose' => $purpose]);
            flash_success('Loan application created successfully.');
            header('Location: ' . url('loans/index.php')); exit;
        }
    } else { flash_error('Please select member and enter a valid amount.'); }
}
?>
<div class="card shadow-sm mx-auto" style="max-width: 780px;">
    <div class="card-header"><?= e($headerTitle) ?></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <?php if ($isMember): ?>
                <div class="col-12"><label class="form-label">Member</label><input class="form-control" type="text" value="<?= e($user['name']) ?>" readonly><input type="hidden" name="member_id" value="<?= e($user['member_id']) ?>"></div>
            <?php else: ?>
                <div class="col-12"><label class="form-label required">Member</label><select class="form-select" name="member_id" required><option value="">Select Member</option><?php foreach($members as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['member_no'].' - '.$m['full_name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label required">Loan Amount</label><input type="number" step="0.01" min="1" class="form-control" name="amount" required></div>
            <div class="col-md-4"><label class="form-label">Interest Rate (%)</label><input type="number" step="0.01" min="0" class="form-control" name="interest_rate" value="10"></div>
            <div class="col-md-4"><label class="form-label required">Application Date</label><input type="date" class="form-control" name="application_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-12"><label class="form-label">Purpose</label><textarea class="form-control" name="purpose"></textarea></div>
            <div class="col-12"><button class="btn btn-primary">Save Application</button> <a class="btn btn-secondary" href="<?= url('loans/index.php') ?>">Cancel</a></div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
