<?php
$page_title = 'Edit Chama';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['superadmin']);
require_once __DIR__ . '/../includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ?');
$stmt->execute([$id]);
$chama = $stmt->fetch();
if (!$chama) {
    flash_error('Chama not found.');
    header('Location: ' . url('chamas/index.php'));
    exit;
}

// Quick suspend / activate from the list page.
// A manual suspension blocks every login; only non-payment suspension leaves the
// billing page reachable so the group can pay itself back in.
if (isset($_GET['status']) && in_array($_GET['status'], ['active', 'suspended'], true)) {
    $reason = $_GET['status'] === 'suspended' ? 'manual' : '';
    $stmt = $pdo->prepare('UPDATE chamas SET status = ?, suspension_reason = ? WHERE id = ?');
    $stmt->execute([$_GET['status'], $reason, $id]);
    flash_success($chama['name'] . ' is now ' . $_GET['status'] . ($reason === 'manual' ? ' (manual - all logins blocked)' : '') . '.');
    header('Location: ' . url('chamas/index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $billing_phone = trim($_POST['billing_phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $plan = $_POST['plan'] ?? 'free';
    $monthly_fee = (float)($_POST['monthly_fee'] ?? 0);
    $monthly_dues = (float)($_POST['monthly_dues'] ?? 0);
    $plan_expires_at = $_POST['plan_expires_at'] ?? '';
    $started_at = $_POST['started_at'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $sms_enabled = ((int)($_POST['sms_enabled'] ?? 0)) === 1 ? 1 : 0;
    $sms_receipts = ((int)($_POST['sms_receipts'] ?? 1)) === 0 ? 0 : 1;
    $sms_reminder_day = max(1, min(28, (int)($_POST['sms_reminder_day'] ?? 5)));

    if ($name === '' || !in_array($plan, ['free', 'pro'], true)) {
        flash_error('Chama name is required and plan must be valid.');
    } else {
        // Clearing the expiry date stops billing; setting a fee without an expiry
        // starts the clock one month from today.
        $expiry = $plan_expires_at !== '' ? $plan_expires_at : null;
        if ($expiry === null && $monthly_fee > 0) {
            $expiry = date('Y-m-d', strtotime('+1 month -1 day'));
        }

        $stmt = $pdo->prepare('UPDATE chamas SET name = ?, phone = ?, billing_phone = ?, email = ?, plan = ?, monthly_fee = ?, monthly_dues = ?, sms_enabled = ?, sms_receipts = ?, sms_reminder_day = ?, plan_expires_at = ?, started_at = ?, notes = ? WHERE id = ?');
        $stmt->execute([$name, $phone, $billing_phone, $email, $plan, max(0, $monthly_fee), max(0, $monthly_dues), $sms_enabled, $sms_receipts, $sms_reminder_day, $expiry, $started_at !== '' ? $started_at : null, $notes, $id]);
        audit_log('chama.update', 'chama', $id, sprintf('Updated chama settings (%s, plan %s, fee KSh %s, dues KSh %s)', $name, $plan, number_format(max(0, $monthly_fee), 2), number_format(max(0, $monthly_dues), 2)), ['before' => ['plan' => $chama['plan'], 'monthly_fee' => $chama['monthly_fee'], 'monthly_dues' => $chama['monthly_dues'] ?? 0]]);
        flash_success('Chama updated successfully.');
        header('Location: ' . url('chamas/index.php'));
        exit;
    }
}
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Edit Chama</h2>
            <div class="text-muted"><?= e($chama['name']) ?> · registration link: <code><?= e(url('register.php?chama=' . $chama['slug'])) ?></code></div>
        </div>
        <a href="<?= url('chamas/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Chamas</a>
    </div>
</div>
<?php $editSub = subscription_status($chama); ?>
<div class="card shadow-sm mb-4 mx-auto" style="max-width: 850px;">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Subscription</span>
        <?= subscription_badge($chama) ?>
    </div>
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div class="small">
            <div>Monthly fee: <strong><?= money($chama['monthly_fee']) ?></strong></div>
            <div>Expires at: <strong><?= $editSub['expires_at'] ? e(date('d M Y', strtotime((string)$editSub['expires_at']))) : 'No expiry set' ?></strong></div>
            <div>Group status:
                <span class="badge bg-<?= $chama['status'] === 'active' ? 'success' : 'danger' ?>"><?= e(ucfirst($chama['status'])) ?></span>
                <?php if (!empty($chama['suspension_reason'])): ?>
                    <span class="text-muted">(<?= e($chama['suspension_reason'] === 'nonpayment' ? 'non-payment - billing page still reachable' : 'manual - all logins blocked') ?>)</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-success btn-sm" href="<?= url('billing/pay.php?chama_id=' . (int)$chama['id']) ?>">Bill by M-Pesa</a>
            <form method="post" action="<?= url('billing/extend.php') ?>" class="d-inline">
                <input type="hidden" name="chama_id" value="<?= (int)$chama['id'] ?>">
                <input type="hidden" name="days" value="30">
                <button class="btn btn-outline-secondary btn-sm">Grant 30 days</button>
            </form>
            <form method="post" action="<?= url('billing/mark_paid.php') ?>" class="d-inline">
                <input type="hidden" name="chama_id" value="<?= (int)$chama['id'] ?>">
                <button class="btn btn-outline-primary btn-sm" onclick="return confirm('Record a cash/bank payment of <?= e(number_format((float)$chama['monthly_fee'], 2)) ?> for 1 month?')">Mark paid (cash)</button>
            </form>
        </div>
    </div>
</div>

<div class="card shadow-sm mx-auto" style="max-width: 850px;">
    <div class="card-header">Chama Details</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-6"><label class="form-label required">Chama Name</label><input class="form-control" name="name" value="<?= e($chama['name']) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e($chama['phone']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= e($chama['email']) ?>"></div>
            <div class="col-md-3"><label class="form-label required">Plan</label>
                <select class="form-select" name="plan">
                    <option value="free" <?= $chama['plan'] === 'free' ? 'selected' : '' ?>>Free (no expiry)</option>
                    <option value="pro" <?= $chama['plan'] === 'pro' ? 'selected' : '' ?>>Pro (paid monthly)</option>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">Monthly Fee (KES)</label><input type="number" step="0.01" min="0" class="form-control" name="monthly_fee" value="<?= e($chama['monthly_fee']) ?>">
                <div class="form-text">Chama pays the platform.</div></div>
            <div class="col-md-3"><label class="form-label">Member dues (KES/mo)</label><input type="number" step="0.01" min="0" class="form-control" name="monthly_dues" value="<?= e($chama['monthly_dues'] ?? 0) ?>">
                <div class="form-text">Suggested amount members pay.</div></div>
            <div class="col-md-3"><label class="form-label">Plan expires at</label><input type="date" class="form-control" name="plan_expires_at" value="<?= e($chama['plan_expires_at'] ?? '') ?>">
                <div class="form-text">Blank + a fee = starts a new month. Blank + no fee = never expires.</div></div>
            <div class="col-md-3"><label class="form-label">Billing phone (M-Pesa)</label><input class="form-control" name="billing_phone" value="<?= e($chama['billing_phone'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Started On</label><input type="date" class="form-control" name="started_at" value="<?= e($chama['started_at'] ?? '') ?>"></div>
            <div class="col-12"><hr class="my-1"><h6 class="text-muted mb-1"><i class="fa-solid fa-comment-sms me-1"></i>SMS settings</h6>
                <div class="text-muted small mb-2">Reminders need <code>cron/reminders.php</code> running daily (or open it in the browser with your token). Receipts go out automatically when money lands.</div></div>
            <div class="col-md-3"><label class="form-label">Monthly dues reminders</label>
                <select class="form-select" name="sms_enabled">
                    <option value="0" <?= (int)($chama['sms_enabled'] ?? 0) === 0 ? 'selected' : '' ?>>Off</option>
                    <option value="1" <?= (int)($chama['sms_enabled'] ?? 0) === 1 ? 'selected' : '' ?>>On</option>
                </select></div>
            <div class="col-md-3"><label class="form-label">Reminder day of month</label><input type="number" min="1" max="28" class="form-control" name="sms_reminder_day" value="<?= e($chama['sms_reminder_day'] ?? 5) ?>">
                <div class="form-text">1-28. One SMS per member per month.</div></div>
            <div class="col-md-3"><label class="form-label">Payment receipt SMS</label>
                <select class="form-select" name="sms_receipts">
                    <option value="1" <?= (int)($chama['sms_receipts'] ?? 1) === 1 ? 'selected' : '' ?>>Send on every payment</option>
                    <option value="0" <?= (int)($chama['sms_receipts'] ?? 1) === 0 ? 'selected' : '' ?>>Off</option>
                </select></div>
            <div class="col-md-6"><label class="form-label">Notes</label><input class="form-control" name="notes" value="<?= e($chama['notes']) ?>"></div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Save Changes</button>
                <a class="btn btn-secondary" href="<?= url('chamas/index.php') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
