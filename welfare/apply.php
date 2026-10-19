<?php
/**
 * Member welfare self-service: submit a request and track its status.
 * Members only ever see their OWN requests; case details stay committee-only.
 */
$page_title = 'My Welfare';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_login();

$user = current_user();
$memberId = (int)($user['member_id'] ?? 0);
if ($memberId <= 0) {
    flash_error('Your login is not linked to a member record.');
    header('Location: ' . url('dashboard.php'));
    exit;
}
$chamaId = current_chama_id();

// Simple str fallback helper (mbstring may be absent on some hosts)
if (!function_exists('mb_substr_str')) {
    function mb_substr_str(string $s, int $len): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if ($amount <= 0) {
        flash_error('Enter an amount greater than zero.');
    } elseif ($reason === '') {
        flash_error('Please choose a reason for the request.');
    } else {
        $stmt = $pdo->prepare("INSERT INTO welfare_payouts (chama_id, member_id, amount, reason, notes, request_date, status)
                               VALUES (?, ?, ?, ?, ?, CURDATE(), 'pending')");
        $stmt->execute([$chamaId, $memberId, $amount, mb_substr_str($reason, 250), mb_substr_str($notes, 250)]);
        audit_log('welfare.apply', 'welfare', (int)$pdo->lastInsertId(), sprintf('Member submitted welfare request for KSh %s', number_format($amount, 2)));
        flash_success('Your welfare request was submitted. The committee will review it and you will see the status here.');
    }
    header('Location: ' . url('welfare/apply.php'));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM welfare_payouts WHERE member_id = ? AND chama_id = ? ORDER BY request_date DESC, id DESC');
$stmt->execute([$memberId, $chamaId]);
$myRequests = $stmt->fetchAll();

$badges = ['pending' => 'secondary', 'approved' => 'info', 'rejected' => 'danger', 'paid' => 'success'];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-head d-flex flex-column flex-md-row justify-content-between align-items-start gap-2">
    <div>
        <h1 class="h3 fw-bold mb-1"><i class="fa-solid fa-heart me-2"></i>Welfare support</h1>
        <div class="text-muted mb-0">Request assistance from your group's welfare fund and track the status here.</div>
    </div>
    <a class="btn btn-light btn-sm" href="<?= url('dashboard.php') ?>"><i class="fa-solid fa-house me-1"></i>Home</a>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header">New request</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label required">Amount needed (KSh)</label>
                        <input type="number" step="0.01" min="1" class="form-control form-control-lg" name="amount" required inputmode="decimal" placeholder="e.g. 3000">
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Reason</label>
                        <select class="form-select" name="reason" required>
                            <option value="">Choose one...</option>
                            <option value="Medical">Medical</option>
                            <option value="Bereavement">Bereavement</option>
                            <option value="Emergency">Emergency</option>
                            <option value="Education">Education</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Extra details (optional)</label>
                        <textarea class="form-control" name="notes" rows="3" maxlength="250" placeholder="Anything the committee should know"></textarea>
                    </div>
                    <button class="btn btn-primary btn-lg w-100" type="submit"><i class="fa-solid fa-paper-plane me-1"></i>Submit request</button>
                    <div class="form-text mt-2">Only your group's committee sees the details of your request.</div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header">My requests</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($myRequests as $w): ?>
                <li class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                        <div>
                            <div class="fw-bold"><?= money($w['amount']) ?> <span class="fw-normal text-muted small">&middot; <?= e($w['reason']) ?></span></div>
                            <div class="small text-muted">Requested <?= e(date('d M Y', strtotime((string)$w['request_date']))) ?></div>
                            <?php if ($w['notes'] !== ''): ?><div class="small text-muted fst-italic">&ldquo;<?= e($w['notes']) ?>&rdquo;</div><?php endif; ?>
                        </div>
                        <span class="badge bg-<?= e($badges[$w['status']] ?? 'secondary') ?>"><?= e(ucfirst($w['status'])) ?></span>
                    </div>
                    <div class="small mt-2">
                        <?php if ($w['status'] === 'pending'): ?>
                            <i class="fa-regular fa-clock text-secondary me-1"></i>Waiting for the committee to decide.
                        <?php elseif ($w['status'] === 'approved'): ?>
                            <i class="fa-solid fa-circle-check text-info me-1"></i>Approved<?= $w['approved_date'] ? ' on ' . e(date('d M Y', strtotime((string)$w['approved_date']))) : '' ?> - payment is being prepared.
                        <?php elseif ($w['status'] === 'paid'): ?>
                            <i class="fa-solid fa-circle-check text-success me-1"></i>Paid. <i class="fa-solid fa-hand-holding-heart text-success ms-1"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-circle-xmark text-danger me-1"></i>Not approved this time. You can talk to the committee or submit a new request.
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if (!$myRequests): ?>
                <li class="list-group-item text-center text-muted py-4">You have not made any welfare requests yet.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
