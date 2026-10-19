<?php
$page_title = 'Bill Chama';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_role(['superadmin']);
require_once __DIR__ . '/../includes/header.php';

$chamaId = (int)($_GET['chama_id'] ?? ($_POST['chama_id'] ?? 0));
$stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ? LIMIT 1');
$stmt->execute([$chamaId]);
$chama = $stmt->fetch();
if (!$chama) {
    flash_error('Chama not found.');
    header('Location: ' . url('billing/index.php'));
    exit;
}

$status = subscription_status($chama);
$billingPhone = chama_billing_phone($chama);
$monthlyFee = (float)$chama['monthly_fee'];
$simulating = mpesa_is_simulation();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    // Annual billing hypothesis: 12 months of service for the price of
    // annual_months_pay (default 10). Priced server-side, never from the form.
    $plan = plan_for_chama($chama);
    $amountOverride = null;
    if (($_POST['period'] ?? 'monthly') === 'annual') {
        $months = 12;
        $base = (float)$chama['monthly_fee'] > 0 ? (float)$chama['monthly_fee'] : (float)$plan['monthly'];
        $amountOverride = $base * (int)($plan['annual_months_pay'] ?: 10);
    } else {
        $months = max(1, min(12, (int)($_POST['months'] ?? 1)));
    }
    $result = initiate_subscription_stk($chama, (string)($_POST['phone'] ?? ''), $userId, $months, $amountOverride);
    if ($result['ok']) {
        flash_success($result['simulated']
            ? 'Simulated M-Pesa request created (demo mode - no Daraja credentials).'
            : 'STK prompt sent. The customer must enter their M-Pesa PIN on their phone.');
        header('Location: ' . url('billing/pay.php?chama_id=' . $chamaId . '&pending=' . $result['payment_id']));
        exit;
    }
    flash_error($result['error']);
    header('Location: ' . url('billing/pay.php?chama_id=' . $chamaId));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_sim') {
    // Demo control only: in sandbox/production a payment can be confirmed
    // solely by Safaricom's callback / status query.
    if (!mpesa_is_simulation()) {
        http_response_code(403);
        flash_error('Simulation is only available in demo mode. Live payments are confirmed by Safaricom.');
        header('Location: ' . url('billing/pay.php'));
        exit;
    }
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM subscription_payments WHERE id = ? AND chama_id = ? LIMIT 1');
    $stmt->execute([$paymentId, $chamaId]);
    $payment = $stmt->fetch();
    if (!$payment || $payment['status'] !== 'pending') {
        flash_error('That request is no longer pending.');
    } elseif (!$simulating) {
        flash_error('Live M-Pesa is configured - payments complete automatically via the callback.');
    } else {
        $receipt = 'SIM' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
        complete_pending_payment($paymentId, $receipt, $payment['mpesa_phone'], (float)$payment['amount']);
        flash_success('Payment recorded (' . $receipt . ') and the plan was extended.');
    }
    header('Location: ' . url('billing/pay.php?chama_id=' . $chamaId));
    exit;
}

$pending = null;
$pendingId = (int)($_GET['pending'] ?? 0);
if ($pendingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM subscription_payments WHERE id = ? AND chama_id = ? LIMIT 1');
    $stmt->execute([$pendingId, $chamaId]);
    $pending = $stmt->fetch() ?: null;
}

$history = $pdo->prepare('SELECT * FROM subscription_payments WHERE chama_id = ? ORDER BY id DESC LIMIT 15');
$history->execute([$chamaId]);
$payments = $history->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Bill <?= e($chama['name']) ?></h2>
            <div class="text-muted">Send an M-Pesa STK prompt for this group's subscription fee.</div>
        </div>
        <a href="<?= url('billing/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Billing</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm mb-4">
            <div class="card-header">Subscription</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><th style="width:45%">Plan</th><td><?= e(ucfirst($chama['plan'])) ?></td></tr>
                        <tr><th>Monthly fee</th><td><?= money($monthlyFee) ?></td></tr>
                        <tr><th>Expires at</th><td><?= $status['expires_at'] ? '<strong>' . e(date('d M Y', strtotime((string)$status['expires_at']))) . '</strong>' : '<span class="text-muted">No expiry set</span>' ?></td></tr>
                        <tr><th>Status</th><td><?= subscription_badge($chama) ?></td></tr>
                        <tr><th>Group status</th><td><span class="badge bg-<?= $chama['status'] === 'active' ? 'success' : 'danger' ?>"><?= e(ucfirst($chama['status'])) ?></span></td></tr>
                        <tr><th>Billing phone</th><td><?= $billingPhone ? e($billingPhone) : '<span class="text-danger">Not set</span>' ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">Send M-Pesa STK prompt</div>
            <div class="card-body">
                <?php if ($monthlyFee < 1): ?>
                    <div class="alert alert-warning mb-3">This chama has no monthly fee, so there is nothing to bill.
                        <a href="<?= url('chamas/edit.php?id=' . $chamaId) ?>">Set the fee</a> first.</div>
                <?php endif; ?>
                <form method="post" class="row g-3">
            <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pay">
                    <input type="hidden" name="chama_id" value="<?= $chamaId ?>">
                    <div class="col-12">
                        <label class="form-label required">Customer phone (M-Pesa)</label>
                        <input class="form-control" name="phone" value="<?= e($billingPhone) ?>" placeholder="0712 345 678" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Billing period</label>
                        <select class="form-select" name="period" id="periodSel">
                            <option value="monthly" selected>Monthly</option>
                            <option value="annual">Annual - 12 months for the price of <?= (int)(plan_for_chama($chama)['annual_months_pay'] ?: 10) ?></option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Months to cover</label>
                        <select class="form-select" name="months">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m === 1 ? 'selected' : '' ?>><?= $m ?> month<?= $m > 1 ? 's' : '' ?> - <?= money($monthlyFee * $m) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-success btn-lg" <?= $monthlyFee < 1 ? 'disabled' : '' ?>>Send STK Push</button>
                    </div>
                </form>
                <?php if ($simulating): ?>
                <div class="alert alert-info small mt-3 mb-0"><strong>Demo mode:</strong> no Daraja credentials yet, so the prompt is simulated and you confirm it below.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <?php if ($pending && $pending['status'] === 'pending'): ?>
        <div class="card shadow-sm mb-4 border-warning">
            <div class="card-header bg-warning-subtle">Waiting for confirmation</div>
            <div class="card-body">
                <p class="mb-2"><strong><?= money($pending['amount']) ?></strong> requested from <strong><?= mpesa_mask_phone((string)$pending['mpesa_phone']) ?></strong>.</p>
                <p class="text-muted small mb-3">Reference: <code><?= e($pending['checkout_request_id']) ?></code></p>
                <div id="pendingState" class="alert alert-secondary py-2 mb-3">Checking with M-Pesa&hellip;</div>
                <?php if ($simulating): ?>
                <form method="post" class="d-inline">
            <?= csrf_field() ?>
                    <input type="hidden" name="action" value="complete_sim">
                    <input type="hidden" name="payment_id" value="<?= (int)$pending['id'] ?>">
                    <input type="hidden" name="chama_id" value="<?= $chamaId ?>">
                    <button class="btn btn-outline-success">Simulate: customer paid</button>
                </form>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="<?= url('billing/pay.php?chama_id=' . $chamaId . '&pending=' . (int)$pending['id']) ?>">Refresh</a>
            </div>
        </div>
        <script>
        (function () {
            var box = document.getElementById('pendingState');
            var tries = 0;
            var timer = setInterval(function () {
                tries++;
                fetch('<?= url('mpesa/status.php') ?>?payment_id=<?= (int)$pending['id'] ?>', { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.status === 'paid') {
                            box.className = 'alert alert-success py-2 mb-3';
                            box.innerHTML = 'Paid! Receipt ' + (d.receipt || 'recorded') + '. Plan now runs to ' + (d.expires_at || '') + '.';
                            clearInterval(timer);
                            setTimeout(function () { window.location.reload(); }, 2000);
                        } else if (d.status === 'failed' || d.status === 'cancelled') {
                            box.className = 'alert alert-danger py-2 mb-3';
                            box.innerHTML = 'Not completed: ' + (d.message || d.status);
                            clearInterval(timer);
                        } else if (tries > 40) {
                            box.className = 'alert alert-warning py-2 mb-3';
                            box.innerHTML = 'Still waiting for the customer. Refresh after they pay.';
                            clearInterval(timer);
                        }
                    })
                    .catch(function () {});
            }, 3000);
        })();
        </script>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header">Payment history</div>
            <div class="card-body table-responsive p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Date</th><th>Period</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$payments): ?>
                        <tr><td colspan="6" class="text-muted p-3">No payments recorded for this chama yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $p):
                        $badge = ['paid' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'cancelled' => 'secondary'][$p['status']] ?? 'secondary';
                    ?>
                        <tr>
                            <td><?= e(date('d M Y H:i', strtotime((string)($p['paid_at'] ?: $p['created_at'])))) ?></td>
                            <td><?= e($p['period_start'] ? date('d M', strtotime((string)$p['period_start'])) : '-') ?> &rarr; <?= e($p['period_end'] ? date('d M Y', strtotime((string)$p['period_end'])) : '-') ?></td>
                            <td><?= money($p['amount']) ?></td>
                            <td><span class="badge bg-light text-dark"><?= e(str_replace('_', ' ', $p['method'])) ?></span></td>
                            <td><code><?= e($p['mpesa_receipt'] ?: '-') ?></code></td>
                            <td><span class="badge bg-<?= e($badge) ?>"><?= e(ucfirst($p['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
