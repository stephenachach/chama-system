<?php
/**
 * The group's OWN M-Pesa collection route (P0 fund safety).
 *
 * Member dues and loan repayments must settle to the GROUP's Paybill/Till.
 * The platform shortcode is used ONLY for subscription fees. Until a route is
 * configured AND verified by the platform operator, live STK collection is
 * refused and the group records payments manually (or stays in demo mode).
 */
$page_title = 'M-Pesa Collection Route';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['admin']);

$chamaId = current_chama_id();
$stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ? LIMIT 1');
$stmt->execute([$chamaId]);
$chama = $stmt->fetch();
if (!$chama) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shortcode = preg_replace('/[^0-9]/', '', (string)($_POST['pay_shortcode'] ?? '')) ?? '';
    $passkey = trim((string)($_POST['pay_passkey'] ?? ''));

    if (($_POST['remove_route'] ?? '') === '1' || ($shortcode === '' && $passkey === '')) {
        // Clearing the route returns the group to manual recording.
        $pdo->prepare("UPDATE chamas SET pay_shortcode = '', pay_passkey = '', pay_verified = 0, pay_verified_at = NULL WHERE id = ?")
            ->execute([$chamaId]);
        audit_log('route.clear', 'chama', $chamaId, 'Group M-Pesa route removed - live collection disabled, manual recording only');
        flash_success('Route removed. Live M-Pesa collection is off until a new route is added.');
    } elseif (strlen($shortcode) < 5 || $passkey === '') {
        flash_error('Enter the full Paybill/Till shortcode and its Daraja passkey.');
    } else {
        // Any change invalidates verification until the operator re-checks it.
        $pdo->prepare('UPDATE chamas SET pay_shortcode = ?, pay_passkey = ?, pay_verified = 0, pay_verified_at = NULL WHERE id = ?')
            ->execute([$shortcode, $passkey, $chamaId]);
        audit_log('route.set', 'chama', $chamaId, sprintf('Group M-Pesa route set to shortcode %s (awaiting operator verification)', $shortcode));
        flash_success('Route saved. Live collection switches on once the platform operator verifies ownership (usually after one test payment).');
    }
    header('Location: ' . url('account/payment_route.php'));
    exit;
}

require_once __DIR__ . '/../includes/header.php';
$status = mpesa_group_route_status($chama);
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="fw-bold mb-1"><i class="fa-solid fa-route me-2"></i>M-Pesa Collection Route</h2>
        <div class="text-muted">Where YOUR members' money lands. The platform never holds group funds.</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header">Your group's Paybill / Till</div>
            <div class="card-body">
                <?php if ($status['state'] === 'simulation'): ?>
                    <div class="alert alert-info small mb-3">Demo mode is on: no real money moves anywhere, so no route is needed yet. When you go live, fill this in first.</div>
                <?php elseif ($status['state'] === 'ready'): ?>
                    <div class="alert alert-success small mb-3"><i class="fa-solid fa-circle-check me-1"></i>Verified: member payments settle to shortcode <strong><?= e($chama['pay_shortcode']) ?></strong> - your account.</div>
                <?php elseif ($status['state'] === 'unverified'): ?>
                    <div class="alert alert-warning small mb-3"><i class="fa-solid fa-hourglass-half me-1"></i>Route saved, waiting for operator verification. Members can still pay by cash/M-Pesa manually recorded by your treasurer.</div>
                <?php else: ?>
                    <div class="alert alert-secondary small mb-3">No route yet: live STK collection is off. Treasurers record cash/M-Pesa payments manually under Contributions.</div>
                <?php endif; ?>

                <form method="post" class="row g-3">
            <?= csrf_field() ?>
                    <div class="col-md-5">
                        <label class="form-label">Paybill / Till shortcode</label>
                        <input class="form-control" name="pay_shortcode" inputmode="numeric" value="<?= e($chama['pay_shortcode']) ?>" placeholder="e.g. 400200">
                        <div class="form-text">The number money should settle to - yours, not the platform's.</div>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Daraja passkey for that shortcode</label>
                        <input type="password" class="form-control" name="pay_passkey" value="<?= e($chama['pay_passkey']) ?>" placeholder="paste from your Daraja portal" autocomplete="new-password">
                        <div class="form-text">Stored in your group record on this server. Rotate it in Daraja any time.</div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary">Save route</button>
                        <?php if ($chama['pay_shortcode'] !== ''): ?>
                        <button class="btn btn-outline-danger" name="remove_route" value="1">Remove route</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header">How your money flows</div>
            <div class="card-body small">
                <ol class="mb-3">
                    <li class="mb-2"><strong>Member dues &amp; loan repayments</strong> &rarr; STK prompt on the member's phone &rarr; settles to <strong>your</strong> Paybill/Till above. The system only records what Safaricom confirms.</li>
                    <li class="mb-2"><strong>Subscription fee</strong> &rarr; the platform's own business route (separate, never mixed with group money).</li>
                    <li class="mb-2"><strong>No route / not verified</strong> &rarr; live STK is refused; treasurer records payments manually. Nothing is ever routed to the platform by default.</li>
                </ol>
                <div class="alert alert-warning small mb-0">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    Only enter a shortcode your group controls. If you plan to pool or invest group
                    funds, or act as more than a record-keeper, get advice from a Kenyan-qualified
                    professional first - this software makes no regulatory claims.
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
