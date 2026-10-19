<?php
$page_title = 'Pay Dues by M-Pesa';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/mpesa_payments.php';
require_role(['admin', 'treasurer', 'member']);

$chama = current_chama();
$user = current_user();
$isMemberRole = $user['role'] === 'member';
$myMemberId = (int)($user['member_id'] ?? 0);
$simulating = mpesa_is_simulation();

// --- Whose dues? Members can only pay their own ----------------------------
$memberId = $isMemberRole ? $myMemberId : (int)($_GET['member_id'] ?? ($_POST['member_id'] ?? 0));
$member = null;
if ($memberId > 0) {
    $member = fetch_in_chama('members', $memberId);
}

// --- Start a payment --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    $targetId = $isMemberRole ? $myMemberId : (int)($_POST['member_id'] ?? 0);
    $result = mpesa_pay_initiate([
        'chama_id' => (int)$chama['id'],
        'purpose' => 'contribution',
        'member_id' => $targetId,
        'amount' => (float)($_POST['amount'] ?? 0),
        'phone' => (string)($_POST['phone'] ?? ''),
        'initiated_by' => (int)$user['id'],
    ]);
    if ($result['ok']) {
        flash_success($result['simulated']
            ? 'Simulated M-Pesa request created (demo mode).'
            : 'M-Pesa prompt sent. Enter your PIN on the phone to complete the payment.');
        header('Location: ' . url('contributions/pay.php?member_id=' . $targetId . '&pending=' . $result['payment_id']));
        exit;
    }
    flash_error($result['error']);
    header('Location: ' . url('contributions/pay.php' . ($targetId ? '?member_id=' . $targetId : '')));
    exit;
}

// --- Simulation: pretend the customer accepted the prompt -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_sim') {
    // Demo control only: in sandbox/production a payment can be confirmed
    // solely by Safaricom's callback / status query.
    if (!mpesa_is_simulation()) {
        http_response_code(403);
        flash_error('Simulation is only available in demo mode. Live payments are confirmed by Safaricom.');
        header('Location: ' . url('contributions/pay.php'));
        exit;
    }
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM mpesa_payments WHERE id = ? AND chama_id = ? LIMIT 1');
    $stmt->execute([$paymentId, (int)$chama['id']]);
    $payment = $stmt->fetch();

    $allowed = $payment && ($user['role'] !== 'member' || (int)$payment['member_id'] === $myMemberId);
    if (!$payment || !$allowed || $payment['status'] !== 'pending') {
        flash_error('That payment request is no longer pending.');
    } elseif (!$simulating) {
        flash_error('Live M-Pesa is configured - payments are confirmed by Safaricom automatically.');
    } else {
        $receipt = 'SIM' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
        mpesa_pay_complete($paymentId, $receipt, $payment['phone'], (float)$payment['amount']);
        flash_success('Payment recorded (' . $receipt . '). The contribution has been added to the member record.');
    }
    header('Location: ' . url('contributions/pay.php?member_id=' . (int)($payment['member_id'] ?? $memberId)));
    exit;
}

// --- Member picker for managers --------------------------------------------
if (!$member && !$isMemberRole) {
    $members = $pdo->prepare('SELECT id, member_no, full_name, phone FROM members WHERE chama_id = ? ORDER BY full_name');
    $members->execute([(int)$chama['id']]);
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="card hero-card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="fw-bold mb-1">Pay Dues by M-Pesa</h2>
            <div class="text-muted">Send an M-Pesa prompt to a member's phone. The payment records itself.</div>
        </div>
    </div>
    <div class="card shadow-sm">
        <div class="card-header">Choose the member who is paying</div>
        <div class="card-body table-responsive p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Member No</th><th>Name</th><th>Phone</th><th width="120"></th></tr></thead>
                <tbody>
                <?php foreach ($members->fetchAll() as $m): ?>
                    <tr>
                        <td><?= e($m['member_no']) ?></td>
                        <td><?= e($m['full_name']) ?></td>
                        <td><?= e($m['phone'] ?: '-') ?></td>
                        <td><a class="btn btn-sm btn-success" href="<?= url('contributions/pay.php?member_id=' . (int)$m['id']) ?>">Pay</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if (!$member) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-warning">Your account is not linked to a member record, so there is nothing to pay against. Ask your group admin to link it.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// --- Suggested amount -------------------------------------------------------
$suggested = (float)($chama['monthly_dues'] ?? 0);
if ($suggested <= 0) {
    $stmt = $pdo->prepare('SELECT amount FROM contributions WHERE member_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$member['id']]);
    $suggested = (float)($stmt->fetchColumn() ?: 0);
}

// --- Pending payment we are watching ----------------------------------------
$pending = null;
$pendingId = (int)($_GET['pending'] ?? 0);
if ($pendingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM mpesa_payments WHERE id = ? AND chama_id = ? AND purpose = ? LIMIT 1');
    $stmt->execute([$pendingId, (int)$chama['id'], 'contribution']);
    $row = $stmt->fetch();
    if ($row && ($user['role'] !== 'member' || (int)$row['member_id'] === $myMemberId)) {
        $pending = $row;
    }
}

$history = $pdo->prepare(
    "SELECT * FROM mpesa_payments
      WHERE chama_id = ? AND purpose = 'contribution' AND member_id = ?
      ORDER BY id DESC LIMIT 15"
);
$history->execute([(int)$chama['id'], (int)$member['id']]);
$payments = $history->fetchAll();

$myTotal = 0.0;
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM contributions WHERE member_id = ?');
$stmt->execute([(int)$member['id']]);
$myTotal = (float)$stmt->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Pay Dues by M-Pesa</h2>
            <div class="text-muted">
                <?= e($member['full_name']) ?> (<?= e($member['member_no']) ?>) &middot;
                total contributed so far: <strong><?= money($myTotal) ?></strong>
            </div>
        </div>
        <?php if (!$isMemberRole): ?>
            <a href="<?= url('contributions/pay.php') ?>" class="btn btn-outline-primary btn-lg">Choose another member</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($simulating): ?>
<div class="alert alert-info">
    <strong>Demo mode:</strong> M-Pesa credentials are not configured yet, so the prompt is simulated and you
    confirm it with one click below. With credentials in <code>config/mpesa-secrets.php</code> the real
    Safaricom prompt goes to the phone and the callback records the payment automatically.
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header">Send M-Pesa prompt</div>
            <div class="card-body">
                <form method="post" class="row g-3">
            <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pay">
                    <input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>">
                    <div class="col-12">
                        <label class="form-label">Paying member</label>
                        <input class="form-control" value="<?= e($member['full_name'] . ' (' . $member['member_no'] . ')') ?>" readonly>
                    </div>
                    <div class="col-6">
                        <label class="form-label required">Amount (KES)</label>
                        <input type="number" step="0.01" min="1" class="form-control" name="amount"
                               value="<?= e($suggested > 0 ? number_format($suggested, 2, '.', '') : '') ?>"
                               placeholder="<?= $suggested > 0 ? e(number_format($suggested, 2)) : 'e.g. 500' ?>" required>
                        <?php if ($suggested > 0): ?>
                            <div class="form-text">Suggested: group monthly dues.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-6">
                        <label class="form-label required">M-Pesa phone</label>
                        <input class="form-control" name="phone" value="<?= e($member['phone']) ?>" placeholder="0712 345 678" required>
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-success btn-lg">Send STK Push</button>
                    </div>
                </form>
                <div class="form-text mt-2">The contribution is recorded automatically when the payment succeeds - no manual entry needed.</div>
            </div>
        </div>
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
                <a class="btn btn-outline-secondary" href="<?= url('contributions/pay.php?member_id=' . (int)$member['id'] . '&pending=' . (int)$pending['id']) ?>">Refresh</a>
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
                            box.innerHTML = 'Paid! Receipt ' + (d.receipt || 'recorded') + '. Contribution added.';
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
            <div class="card-header">M-Pesa payments for this member</div>
            <div class="card-body table-responsive p-0">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead><tr><th>When</th><th class="text-end">Amount</th><th>Receipt</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$payments): ?>
                        <tr><td colspan="4" class="text-muted p-3">No M-Pesa payments yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $p):
                        $badge = ['paid' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'cancelled' => 'secondary'][$p['status']] ?? 'secondary';
                    ?>
                        <tr>
                            <td><?= e(date('d M Y H:i', strtotime((string)($p['paid_at'] ?: $p['created_at'])))) ?></td>
                            <td class="text-end"><?= money($p['amount']) ?></td>
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
