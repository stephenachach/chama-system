<?php
$page_title = 'Billing';
require_once __DIR__ . '/../config/auth.php';
require_role(['superadmin']);
require_once __DIR__ . '/../includes/header.php';

subscription_maintenance();

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$chamas = $pdo->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM members m WHERE m.chama_id = c.id) AS member_count,
        (SELECT COALESCE(SUM(amount),0) FROM subscription_payments sp
          WHERE sp.chama_id = c.id AND sp.status = 'paid') AS lifetime_paid,
        (SELECT MAX(sp.paid_at) FROM subscription_payments sp
          WHERE sp.chama_id = c.id AND sp.status = 'paid') AS last_paid_at
    FROM chamas c ORDER BY (c.plan_expires_at IS NULL), c.plan_expires_at ASC, c.id DESC
")->fetchAll();

$mrr = (float)$pdo->query("SELECT COALESCE(SUM(monthly_fee),0) FROM chamas WHERE monthly_fee > 0 AND status = 'active'")->fetchColumn();
$collectedThisMonth = subscription_revenue($monthStart, $monthEnd);
$collectedAllTime = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status = 'paid'")->fetchColumn();

$expiringSoon = 0;
$expiredCount = 0;
$suspendedNonPayment = 0;
$noExpiryPaid = [];
foreach ($chamas as $c) {
    $s = subscription_status($c);
    if ($s['state'] === 'expiring' || $s['state'] === 'grace') {
        $expiringSoon++;
    }
    if ($s['state'] === 'expired') {
        $expiredCount++;
    }
    if ($c['status'] !== 'active' && ($c['suspension_reason'] ?? '') === 'nonpayment') {
        $suspendedNonPayment++;
    }
    if ((float)$c['monthly_fee'] > 0 && empty($c['plan_expires_at'])) {
        $noExpiryPaid[] = $c;
    }
}

$pendingStmt = $pdo->query("SELECT sp.*, c.name AS chama_name FROM subscription_payments sp
    JOIN chamas c ON c.id = sp.chama_id
    WHERE sp.status = 'pending' ORDER BY sp.id DESC LIMIT 10");
$pending = $pendingStmt->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Subscription Billing</h2>
            <div class="text-muted">Plan expiry, M-Pesa collection and automatic suspension for every chama.</div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('chamas/create.php') ?>" class="btn btn-primary btn-lg">New Chama</a>
            <a href="<?= url('mpesa/logs.php') ?>" class="btn btn-outline-secondary btn-lg">M-Pesa Logs</a>
        </div>
    </div>
</div>

<div class="row row-cols-1 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="card stat-card bg-primary"><div class="card-body"><div class="icon">💰</div><h6 class="mb-2">Collected This Month</h6><h2><?= money($collectedThisMonth) ?></h2></div></div></div>
    <div class="col"><div class="card stat-card bg-success"><div class="card-body"><div class="icon">📈</div><h6 class="mb-2">Monthly Recurring</h6><h2><?= money($mrr) ?></h2></div></div></div>
    <div class="col"><div class="card stat-card bg-warning"><div class="card-body"><div class="icon">⏳</div><h6 class="mb-2">Expiring / Grace</h6><h2><?= e($expiringSoon) ?></h2></div></div></div>
    <div class="col"><div class="card stat-card bg-danger"><div class="card-body"><div class="icon">🚫</div><h6 class="mb-2">Suspended (Non-payment)</h6><h2><?= e($suspendedNonPayment) ?></h2></div></div></div>
</div>

<?php if (mpesa_is_simulation()): ?>
<div class="alert alert-info">
    <strong>M-Pesa is in demo mode.</strong> No Daraja credentials are configured, so STK requests are simulated
    and you complete them with one click. To take real money, create
    <code>config/mpesa-secrets.php</code> from <code>config/mpesa-secrets.example.php</code> and fill in your
    Consumer Key, Consumer Secret, short code and passkey.
    <span class="badge bg-secondary ms-2">Total collected (all time): <?= money($collectedAllTime) ?></span>
</div>
<?php else: ?>
<div class="alert alert-success">
    <strong>M-Pesa live mode</strong> (<?= e(ucfirst(MPESA_ENV)) ?>) &middot; short code <code><?= e(MPESA_SHORTCODE) ?></code>
    &middot; callback <code class="small"><?= e(mpesa_callback_url()) ?></code>
</div>
<?php endif; ?>

<?php if ($noExpiryPaid): ?>
<div class="alert alert-warning">
    <strong><?= count($noExpiryPaid) ?> paid chama<?= count($noExpiryPaid) === 1 ? '' : 's' ?> ha<?= count($noExpiryPaid) === 1 ? 's' : 've' ?> no expiry date</strong>
    &mdash; they will never be billed or auto-suspended. Open each one and set "Plan expires at" to start the clock:
    <?php foreach ($noExpiryPaid as $c): ?>
        <a href="<?= url('chamas/edit.php?id=' . (int)$c['id']) ?>"><?= e($c['name']) ?></a><?= $c === end($noExpiryPaid) ? '.' : ',' ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($pending): ?>
<div class="card shadow-sm mb-4 border-warning">
    <div class="card-header bg-warning-subtle">Awaiting confirmation</div>
    <div class="card-body table-responsive p-0">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Chama</th><th>Amount</th><th>Phone</th><th>Sent</th><th>Reference</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pending as $p): ?>
                <tr>
                    <td><?= e($p['chama_name']) ?></td>
                    <td><?= money($p['amount']) ?></td>
                    <td><?= mpesa_mask_phone((string)$p['mpesa_phone']) ?></td>
                    <td><?= e(date('d M H:i', strtotime((string)$p['created_at']))) ?></td>
                    <td><code class="small"><?= e($p['checkout_request_id']) ?></code></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="<?= url('billing/pay.php?chama_id=' . (int)$p['chama_id'] . '&pending=' . (int)$p['id']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>All chamas (soonest expiry first)</span>
        <span class="text-muted small">Grace period: <?= (int)SUBSCRIPTION_GRACE_DAYS ?> days after expiry</span>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Chama</th><th>Members</th><th>Plan</th><th>Fee</th>
                    <th>Expires at</th><th>Status</th><th>Last paid</th><th>Lifetime</th><th width="280">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($chamas as $c): $s = subscription_status($c); ?>
                <tr>
                    <td><strong><?= e($c['name']) ?></strong><br><small class="text-muted">/<?= e($c['slug']) ?></small></td>
                    <td><?= e($c['member_count']) ?></td>
                    <td><span class="badge bg-<?= $c['plan'] === 'pro' ? 'primary' : 'secondary' ?>"><?= e(ucfirst($c['plan'])) ?></span></td>
                    <td><?= money($c['monthly_fee']) ?></td>
                    <td>
                        <?php if ($s['expires_at']): ?>
                            <strong><?= e(date('d M Y', strtotime((string)$s['expires_at']))) ?></strong><br>
                            <span class="badge bg-<?= e($s['class']) ?>"><?= e($s['label']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">No expiry</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $c['status'] === 'active' ? 'success' : 'danger' ?>"><?= e(ucfirst($c['status'])) ?></span>
                        <?php if (!empty($c['suspension_reason'])): ?>
                            <br><small class="text-muted"><?= e(ucfirst(str_replace('nonpayment', 'non-payment', $c['suspension_reason']))) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e($c['last_paid_at'] ? date('d M Y', strtotime((string)$c['last_paid_at'])) : '-') ?></td>
                    <td><?= money($c['lifetime_paid']) ?></td>
                    <td>
                        <a href="<?= url('billing/pilots.php') ?>" class="btn btn-outline-warning"><i class="fa-solid fa-rocket me-1"></i>Pilot pipeline</a>
                <a class="btn btn-sm btn-success" href="<?= url('billing/pay.php?chama_id=' . (int)$c['id']) ?>">Bill M-Pesa</a>
                        <form method="post" action="<?= url('billing/mark_paid.php') ?>" class="d-inline">
                            <input type="hidden" name="chama_id" value="<?= (int)$c['id'] ?>">
                            <button class="btn btn-sm btn-outline-primary" onclick="return confirm('Record <?= e($c['name']) ?> as paid by cash/bank for 1 month?')">Mark paid</button>
                        </form>
                        <form method="post" action="<?= url('billing/extend.php') ?>" class="d-inline">
                            <input type="hidden" name="chama_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="days" value="30">
                            <button class="btn btn-sm btn-outline-secondary" title="Give 30 days free">+30d</button>
                        </form>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= url('chamas/edit.php?id=' . (int)$c['id']) ?>">Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
