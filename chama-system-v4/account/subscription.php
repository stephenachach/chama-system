<?php
$page_title = 'Subscription';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin', 'treasurer']);
require_once __DIR__ . '/../includes/header.php';

$chama = current_chama();
if (!$chama) {
    echo '<div class="alert alert-warning">No group is linked to your account.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$userId = (int)$user['id'];
$restricted = subscription_restricted_mode();
$status = subscription_status($chama);
$amountDue = subscription_amount_due($chama);
$period = subscription_next_period($chama, max(1, (int)SUBSCRIPTION_BILLING_MONTHS));
$billingPhone = chama_billing_phone($chama);
$simulating = mpesa_is_simulation();

// --- Pay by M-Pesa ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    $result = initiate_subscription_stk($chama, (string)($_POST['phone'] ?? ''), $userId);
    if ($result['ok']) {
        flash_success($result['simulated']
            ? 'Simulated M-Pesa request created (no Daraja credentials configured yet).'
            : 'M-Pesa prompt sent. Check the phone and enter your PIN.');
        header('Location: ' . url('account/subscription.php?pending=' . $result['payment_id']));
        exit;
    }
    flash_error($result['error']);
    header('Location: ' . url('account/subscription.php'));
    exit;
}

// --- Simulation: pretend the customer accepted the prompt --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_sim') {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM subscription_payments WHERE id = ? AND chama_id = ? LIMIT 1');
    $stmt->execute([$paymentId, (int)$chama['id']]);
    $payment = $stmt->fetch();
    if (!$payment || $payment['status'] !== 'pending') {
        flash_error('That payment request is no longer pending.');
    } elseif (!$simulating) {
        flash_error('Live M-Pesa is configured, so payments are completed by Safaricom automatically.');
    } else {
        $receipt = 'SIM' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
        complete_pending_payment($paymentId, $receipt, $payment['mpesa_phone'], (float)$payment['amount']);
        $stmt = $pdo->prepare('SELECT plan_expires_at FROM chamas WHERE id = ?');
        $stmt->execute([(int)$chama['id']]);
        $newExpiry = (string)$stmt->fetchColumn();
        $chama['plan_expires_at'] = $newExpiry;
        $chama['status'] = 'active';
        $chama['suspension_reason'] = '';
        $_SESSION['user']['chama_name'] = $chama['name'];
        flash_success('Payment recorded (' . $receipt . '). Your plan now runs to ' . date('d M Y', strtotime($newExpiry)) . '.');
    }
    header('Location: ' . url('account/subscription.php'));
    exit;
}

// --- Pending payment we should watch ---------------------------------------
$pending = null;
$pendingId = (int)($_GET['pending'] ?? 0);
if ($pendingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM subscription_payments WHERE id = ? AND chama_id = ? LIMIT 1');
    $stmt->execute([$pendingId, (int)$chama['id']]);
    $pending = $stmt->fetch() ?: null;
}

$history = $pdo->prepare('SELECT * FROM subscription_payments WHERE chama_id = ? ORDER BY id DESC LIMIT 25');
$history->execute([(int)$chama['id']]);
$payments = $history->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="fw-bold mb-1">Subscription</h2>
        <div class="text-muted">Your group's plan, expiry date and M-Pesa payments.</div>
    </div>
</div>

<?php if ($status['state'] === 'expiring' || $status['state'] === 'grace'): ?>
<div class="alert alert-warning">
    <strong><?= e($status['label']) ?></strong>
    (<?= e($status['expires_at'] ? date('d M Y', strtotime((string)$status['expires_at'])) : '') ?>).
    Pay now to avoid interruption.
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Current plan</span>
                <?= subscription_badge($chama) ?>
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><th style="width:45%">Group</th><td><?= e($chama['name']) ?></td></tr>
                        <tr><th>Plan</th><td><?= e(ucfirst($chama['plan'])) ?></td></tr>
                        <tr><th>Monthly fee</th><td><?= money($chama['monthly_fee']) ?></td></tr>
                        <tr>
                            <th>Expires at</th>
                            <td>
                                <?php if ($status['expires_at']): ?>
                                    <strong><?= e(date('d M Y', strtotime((string)$status['expires_at']))) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">No expiry set</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr><th>Next period</th><td><?= e(date('d M Y', strtotime($period['start']))) ?> &rarr; <?= e(date('d M Y', strtotime($period['end']))) ?></td></tr>
                    </tbody>
                </table>
                <?php if ($simulating): ?>
                <div class="alert alert-info small mt-3 mb-0">
                    <strong>Demo mode:</strong> M-Pesa credentials are not configured yet
                    (<code>config/mpesa-secrets.php</code>), so requests are simulated.
                    Everything else behaves exactly like a live payment.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm mb-4">
            <div class="card-header">Pay by M-Pesa</div>
            <div class="card-body">
                <?php if ($amountDue < 1): ?>
                    <p class="text-muted mb-0">No monthly fee is set for this group. Ask the platform operator to set one.</p>
                <?php else: ?>
                <form method="post" class="row g-3 align-items-end">
                    <input type="hidden" name="action" value="pay">
                    <div class="col-md-6">
                        <label class="form-label required">M-Pesa phone number</label>
                        <input class="form-control" name="phone" value="<?= e($billingPhone) ?>" placeholder="0712 345 678" required>
                        <div class="form-text">You will get an STK prompt on this number.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Amount</label>
                        <input class="form-control" value="<?= e(number_format($amountDue, 2)) ?>" readonly>
                    </div>
                    <div class="col-md-3 d-grid">
                        <button class="btn btn-success btn-lg">Pay <?= money($amountDue) ?></button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($pending && $pending['status'] === 'pending'): ?>
        <div class="card shadow-sm mb-4 border-warning">
            <div class="card-header bg-warning-subtle">Waiting for payment confirmation</div>
            <div class="card-body">
                <p class="mb-2">
                    A request for <strong><?= money($pending['amount']) ?></strong> was sent to
                    <strong><?= mpesa_mask_phone((string)$pending['mpesa_phone']) ?></strong>.
                </p>
                <p class="text-muted small mb-3">
                    Reference: <code><?= e($pending['checkout_request_id']) ?></code>
                </p>
                <div id="pendingState" class="alert alert-secondary py-2 mb-3">Checking with M-Pesa&hellip;</div>
                <?php if ($simulating): ?>
                <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="complete_sim">
                    <input type="hidden" name="payment_id" value="<?= (int)$pending['id'] ?>">
                    <button class="btn btn-outline-success">Simulate: customer accepted &amp; paid</button>
                </form>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="<?= url('account/subscription.php') ?>">Refresh</a>
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
                            box.innerHTML = 'Paid! Receipt ' + (d.receipt || 'recorded') + '. Restoring access&hellip;';
                            clearInterval(timer);
                            setTimeout(function () { window.location = '<?= url('dashboard.php') ?>'; }, 1500);
                        } else if (d.status === 'failed' || d.status === 'cancelled') {
                            box.className = 'alert alert-danger py-2 mb-3';
                            box.innerHTML = 'Payment not completed: ' + (d.message || d.status);
                            clearInterval(timer);
                        } else if (tries > 40) {
                            box.className = 'alert alert-warning py-2 mb-3';
                            box.innerHTML = 'Still waiting. Refresh this page after paying, or contact the platform operator.';
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
                    <thead>
                        <tr><th>Date</th><th>Period covered</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$payments): ?>
                        <tr><td colspan="6" class="text-muted p-3">No payments yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= e($p['paid_at'] ? date('d M Y H:i', strtotime((string)$p['paid_at'])) : date('d M Y H:i', strtotime((string)$p['created_at']))) ?></td>
                            <td><?= e($p['period_start'] ? date('d M', strtotime((string)$p['period_start'])) : '-') ?> &rarr; <?= e($p['period_end'] ? date('d M Y', strtotime((string)$p['period_end'])) : '-') ?></td>
                            <td><?= money($p['amount']) ?></td>
                            <td><span class="badge bg-light text-dark"><?= e(str_replace('_', ' ', $p['method'])) ?></span></td>
                            <td><code><?= e($p['mpesa_receipt'] ?: '-') ?></code></td>
                            <td>
                                <?php
                                $badge = ['paid' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'cancelled' => 'secondary'][$p['status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= e($badge) ?>"><?= e(ucfirst($p['status'])) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
