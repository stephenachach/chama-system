<?php
$page_title = 'Repay Loan by M-Pesa';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/mpesa_payments.php';
require_role(['admin', 'treasurer', 'member']);

$chama = current_chama();
$user = current_user();
$isMemberRole = $user['role'] === 'member';
$myMemberId = (int)($user['member_id'] ?? 0);
$simulating = mpesa_is_simulation();

$loanId = (int)($_GET['id'] ?? ($_POST['loan_id'] ?? 0));
$stmt = $pdo->prepare('SELECT l.*, m.member_no, m.full_name, m.phone FROM loans l JOIN members m ON m.id = l.member_id WHERE l.id = ? AND l.chama_id = ? LIMIT 1');
$stmt->execute([$loanId, (int)$chama['id']]);
$loan = $stmt->fetch();

if (!$loan) {
    flash_error('Loan not found.');
    header('Location: ' . url('loans/index.php'));
    exit;
}
// A member may only repay their own loan.
if ($isMemberRole && (int)$loan['member_id'] !== $myMemberId) {
    flash_error('That loan belongs to another member.');
    header('Location: ' . url('loans/index.php'));
    exit;
}

// --- Start a repayment ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    $result = mpesa_pay_initiate([
        'chama_id' => (int)$chama['id'],
        'purpose' => 'loan_repayment',
        'loan_id' => $loanId,
        'member_id' => (int)$loan['member_id'],
        'amount' => (float)($_POST['amount'] ?? 0),
        'phone' => (string)($_POST['phone'] ?? ''),
        'initiated_by' => (int)$user['id'],
    ]);
    if ($result['ok']) {
        flash_success($result['simulated']
            ? 'Simulated M-Pesa request created (demo mode).'
            : 'M-Pesa prompt sent. Enter your PIN on the phone to complete the repayment.');
        header('Location: ' . url('loans/repay.php?id=' . $loanId . '&pending=' . $result['payment_id']));
        exit;
    }
    flash_error($result['error']);
    header('Location: ' . url('loans/repay.php?id=' . $loanId));
    exit;
}

// --- Simulation: pretend the customer accepted the prompt -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_sim') {
    // Demo control only: in sandbox/production a payment can be confirmed
    // solely by Safaricom's callback / status query.
    if (!mpesa_is_simulation()) {
        http_response_code(403);
        flash_error('Simulation is only available in demo mode. Live payments are confirmed by Safaricom.');
        header('Location: ' . url('loans/repay.php'));
        exit;
    }
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM mpesa_payments WHERE id = ? AND chama_id = ? AND purpose = ? LIMIT 1');
    $stmt->execute([$paymentId, (int)$chama['id'], 'loan_repayment']);
    $payment = $stmt->fetch();

    $allowed = $payment && (int)$payment['loan_id'] === $loanId
        && ($user['role'] !== 'member' || (int)$payment['member_id'] === $myMemberId);
    if (!$payment || !$allowed || $payment['status'] !== 'pending') {
        flash_error('That payment request is no longer pending.');
    } elseif (!$simulating) {
        flash_error('Live M-Pesa is configured - payments are confirmed by Safaricom automatically.');
    } else {
        $receipt = 'SIM' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
        mpesa_pay_complete($paymentId, $receipt, $payment['phone'], (float)$payment['amount']);
        flash_success('Repayment recorded (' . $receipt . '). The loan balance has been updated.');
    }
    header('Location: ' . url('loans/repay.php?id=' . $loanId));
    exit;
}

$balance = (float)$loan['balance'];
$pending = null;
$pendingId = (int)($_GET['pending'] ?? 0);
if ($pendingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM mpesa_payments WHERE id = ? AND chama_id = ? AND purpose = ? AND loan_id = ? LIMIT 1');
    $stmt->execute([$pendingId, (int)$chama['id'], 'loan_repayment', $loanId]);
    $row = $stmt->fetch();
    if ($row && ($user['role'] !== 'member' || (int)$row['member_id'] === $myMemberId)) {
        $pending = $row;
    }
}

$repayments = $pdo->prepare('SELECT * FROM loan_repayments WHERE loan_id = ? ORDER BY id DESC LIMIT 20');
$repayments->execute([$loanId]);
$rows = $repayments->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Repay Loan by M-Pesa</h2>
            <div class="text-muted">
                <?= e($loan['full_name']) ?> (<?= e($loan['member_no']) ?>) &middot;
                applied <?= e(date('d M Y', strtotime((string)$loan['application_date']))) ?>
            </div>
        </div>
        <a href="<?= url('loans/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Loans</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm mb-4">
            <div class="card-header">Loan summary</div>
            <div class="card-body">
                <table class="table table-sm mb-3">
                    <tbody>
                        <tr><th style="width:50%">Borrowed</th><td><?= money($loan['amount']) ?></td></tr>
                        <tr><th>Interest</th><td><?= e(number_format((float)$loan['interest_rate'], 2)) ?>%</td></tr>
                        <tr><th>Total payable</th><td><?= money($loan['total_payable']) ?></td></tr>
                        <tr><th>Repaid so far</th><td><?= money((float)$loan['total_payable'] - $balance) ?></td></tr>
                        <tr><th>Outstanding</th><td><strong><?= money($balance) ?></strong></td></tr>
                        <tr><th>Status</th><td><span class="badge bg-<?= $loan['status'] === 'approved' ? 'success' : ($loan['status'] === 'paid' ? 'info' : 'secondary') ?>"><?= e(ucfirst($loan['status'])) ?></span></td></tr>
                    </tbody>
                </table>
                <?php if ($balance <= 0): ?>
                    <div class="alert alert-success mb-0">This loan is fully repaid. Asante!</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($balance > 0): ?>
        <div class="card shadow-sm">
            <div class="card-header">Send M-Pesa prompt</div>
            <div class="card-body">
                <form method="post" class="row g-3">
            <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pay">
                    <input type="hidden" name="loan_id" value="<?= $loanId ?>">
                    <div class="col-6">
                        <label class="form-label required">Amount (KES)</label>
                        <input type="number" step="0.01" min="1" max="<?= e(number_format($balance, 2, '.', '')) ?>"
                               class="form-control" name="amount" value="<?= e(number_format($balance, 2, '.', '')) ?>" required>
                        <div class="form-text">Max = outstanding balance.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label required">M-Pesa phone</label>
                        <input class="form-control" name="phone" value="<?= e($loan['phone']) ?>" placeholder="0712 345 678" required>
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-success btn-lg">Send STK Push</button>
                    </div>
                </form>
                <div class="form-text mt-2">The repayment and the new balance are updated automatically when the payment succeeds.</div>
                <?php if ($simulating): ?>
                <div class="alert alert-info small mt-3 mb-0"><strong>Demo mode:</strong> confirm the simulated prompt below.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-7">
        <?php if ($pending && $pending['status'] === 'pending'): ?>
        <div class="card shadow-sm mb-4 border-warning">
            <div class="card-header bg-warning-subtle">Waiting for payment confirmation</div>
            <div class="card-body">
                <p class="mb-2"><strong><?= money($pending['amount']) ?></strong> requested from <strong><?= mpesa_mask_phone((string)$pending['phone']) ?></strong>.</p>
                <p class="text-muted small mb-3">Reference: <code><?= e($pending['checkout_request_id']) ?></code></p>
                <div id="pendingState" class="alert alert-secondary py-2 mb-3">Checking with M-Pesa&hellip;</div>
                <?php if ($simulating): ?>
                <form method="post" class="d-inline">
            <?= csrf_field() ?>
                    <input type="hidden" name="action" value="complete_sim">
                    <input type="hidden" name="payment_id" value="<?= (int)$pending['id'] ?>">
                    <button class="btn btn-outline-success">Simulate: customer paid</button>
                </form>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="<?= url('loans/repay.php?id=' . $loanId . '&pending=' . (int)$pending['id']) ?>">Refresh</a>
            </div>
        </div>
        <script>
        (function () {
            var box = document.getElementById('pendingState');
            var tries = 0;
            var timer = setInterval(function () {
                tries++;
                fetch('<?= url('mpesa/status.php') ?>?pay=<?= (int)$pending['id'] ?>', { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.status === 'paid') {
                            box.className = 'alert alert-success py-2 mb-3';
                            box.innerHTML = 'Paid! Receipt ' + (d.receipt || 'recorded') + '. Balance: KES ' + Number(d.loan_balance || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
                            clearInterval(timer);
                            setTimeout(function () { window.location.reload(); }, 2000);
                        } else if (d.status === 'failed' || d.status === 'cancelled') {
                            box.className = 'alert alert-danger py-2 mb-3';
                            box.innerHTML = 'Payment not completed: ' + (d.message || d.status);
                            clearInterval(timer);
                        } else if (tries > 40) {
                            box.className = 'alert alert-warning py-2 mb-3';
                            box.innerHTML = 'Still waiting. Refresh this page after paying.';
                            clearInterval(timer);
                        }
                    })
                    .catch(function () {});
            }, 3000);
        })();
        </script>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header">Repayment history</div>
            <div class="card-body table-responsive p-0">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead><tr><th>Date</th><th class="text-end">Amount</th><th>Reference</th><th>Notes</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="4" class="text-muted p-3">No repayments recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e(date('d M Y', strtotime((string)$r['payment_date']))) ?></td>
                            <td class="text-end"><?= money($r['amount']) ?></td>
                            <td><code><?= e($r['reference'] ?: '-') ?></code></td>
                            <td class="small text-muted"><?= e($r['notes']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
