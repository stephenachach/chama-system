<?php
/**
 * Pilot pipeline (Phase 4): every free-trial chama, what it owes you, and
 * one-click actions to collect the setup fee, extend a pilot, or start billing.
 * Superadmin only.
 */
$page_title = 'Pilot Pipeline';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['superadmin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['chama_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $chama = $stmt->fetch();

    if (!$chama) {
        flash_error('Chama not found.');
    } else {
        switch ($action) {
            case 'setup_paid':
                $pdo->prepare('UPDATE chamas SET setup_fee_paid = 1 WHERE id = ?')->execute([$id]);
                audit_log('pilot.setup_paid', 'chama', $id, sprintf('%s: setup fee KSh %s marked PAID', $chama['name'], number_format((float)$chama['setup_fee'], 2)));
                flash_success($chama['name'] . ': setup fee marked paid.');
                break;
            case 'setup_unpaid':
                $pdo->prepare('UPDATE chamas SET setup_fee_paid = 0 WHERE id = ?')->execute([$id]);
                audit_log('pilot.setup_unpaid', 'chama', $id, sprintf('%s: setup fee KSh %s marked outstanding', $chama['name'], number_format((float)$chama['setup_fee'], 2)));
                flash_success($chama['name'] . ': setup fee marked outstanding.');
                break;
            case 'extend':
                $base = max(date('Y-m-d'), (string)($chama['plan_expires_at'] ?? date('Y-m-d')));
                $new = date('Y-m-d', strtotime($base . ' +30 days'));
                $pdo->prepare('UPDATE chamas SET plan_expires_at = ? WHERE id = ?')->execute([$new, $id]);
                audit_log('pilot.extend', 'chama', $id, sprintf('%s: pilot extended by 30 days to %s', $chama['name'], date('d M Y', strtotime($new))));
                flash_success($chama['name'] . ' extended to ' . date('d M Y', strtotime($new)) . '.');
                break;
            case 'start_billing':
                $pdo->prepare('UPDATE chamas SET plan_expires_at = CURDATE() WHERE id = ?')->execute([$id]);
                audit_log('pilot.start_billing', 'chama', $id, sprintf('%s: pilot ended - subscription billing starts now (KSh %s/mo)', $chama['name'], number_format((float)$chama['monthly_fee'], 2)));
                flash_success($chama['name'] . ': billing starts now. They will see the renew button at next login.');
                break;
            case 'verify_route':
                $pdo->prepare('UPDATE chamas SET pay_verified = 1, pay_verified_at = NOW() WHERE id = ? AND pay_shortcode <> ?')->execute([$id, '']);
                audit_log('route.verify', 'chama', $id, sprintf('%s: M-Pesa route %s verified - live group collection ENABLED on their shortcode', $chama['name'], $chama['pay_shortcode']));
                flash_success($chama['name'] . ': route verified. Member money now settles to THEIR shortcode.');
                break;
            case 'unverify_route':
                $pdo->prepare('UPDATE chamas SET pay_verified = 0, pay_verified_at = NULL WHERE id = ?')->execute([$id]);
                audit_log('route.unverify', 'chama', $id, sprintf('%s: route verification withdrawn - live collection disabled', $chama['name']));
                flash_success($chama['name'] . ': verification withdrawn.');
                break;
            case 'sms_topup':
                $units = max(0, (int)($_POST['units'] ?? 0));
                $pdo->prepare('UPDATE chamas SET sms_balance = sms_balance + ? WHERE id = ?')->execute([$units, $id]);
                audit_log('sms.topup', 'chama', $id, sprintf('%s: SMS credit +%d units', $chama['name'], $units));
                flash_success($chama['name'] . ': +' . $units . ' SMS units.');
                break;
            case 'convert':
                $pdo->prepare('UPDATE chamas SET is_pilot = 0 WHERE id = ?')->execute([$id]);
                audit_log('pilot.convert', 'chama', $id, sprintf('%s: converted to a regular paying customer', $chama['name']));
                flash_success($chama['name'] . ' is now a regular customer. Congrats!');
                break;
            default:
                flash_error('Unknown action.');
        }
    }
    header('Location: ' . url('billing/pilots.php'));
    exit;
}

$stmt = $pdo->query(
    "SELECT c.*, u.name AS admin_name, u.email AS admin_email
       FROM chamas c
       LEFT JOIN users u ON u.chama_id = c.id AND u.role = 'admin'
      WHERE c.is_pilot = 1
      ORDER BY c.plan_expires_at ASC"
);
$pilots = $stmt->fetchAll();

$active = array_filter($pilots, fn($p) => $p['status'] === 'active' && ($p['plan_expires_at'] === null || $p['plan_expires_at'] >= date('Y-m-d')));
$owed = 0.0;
foreach ($pilots as $p) {
    if ((int)$p['setup_fee_paid'] === 0) {
        $owed += (float)$p['setup_fee'];
    }
}
$endingSoon = array_filter($pilots, function ($p) {
    return $p['plan_expires_at'] !== null && $p['plan_expires_at'] >= date('Y-m-d') && strtotime((string)$p['plan_expires_at']) <= strtotime('+14 days');
});

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1"><i class="fa-solid fa-rocket me-2"></i>Pilot Pipeline</h2>
            <div class="text-muted">Free-trial groups, what they owe, and the clicks that turn them into revenue.</div>
        </div>
        <a href="<?= url('billing/index.php') ?>" class="btn btn-outline-primary btn-lg">Billing</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Active pilots</div><h3 class="mb-0"><?= count($active) ?></h3></div></div></div>
    <div class="col-6 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Setup fees outstanding</div><h3 class="mb-0 text-warning"><?= e(money($owed)) ?></h3></div></div></div>
    <div class="col-6 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Pilots ending in 14 days</div><h3 class="mb-0 <?= count($endingSoon) ? 'text-danger' : '' ?>"><?= count($endingSoon) ?></h3></div></div></div>
    <div class="col-6 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Pilot window</div><h3 class="mb-0"><?= PILOT_DAYS ?> days</h3></div></div></div>
</div>

<div class="card shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Chama</th>
                    <th>Contact</th>
                    <th>Tier</th>
                    <th>Setup fee</th>
                    <th>Pilot ends</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$pilots): ?>
                <tr><td colspan="7" class="text-muted py-4">No pilots yet. Share your site link - or <a href="<?= url('start.php') ?>">fill the signup form yourself</a> while demoing to a client.</td></tr>
            <?php endif; ?>
            <?php foreach ($pilots as $p):
                $tier = plan_tier((string)$p['tier']);
                $daysLeft = $p['plan_expires_at'] !== null ? (int)floor((strtotime((string)$p['plan_expires_at']) - strtotime(date('Y-m-d'))) / 86400) : null;
            ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= e($p['name']) ?></div>
                        <div class="text-muted small"><?= e($p['notes'] !== '' ? str_cut($p['notes'], 60) : '') ?></div>
                    </td>
                    <td>
                        <div><?= e($p['admin_name'] ?? '-') ?></div>
                        <div class="text-muted small"><?= e($p['admin_email'] ?? '') ?></div>
                        <div class="text-muted small"><?= e($p['phone']) ?></div>
                    </td>
                    <td>
                        <span class="badge bg-secondary"><?= e($tier['label'] ?? ucfirst((string)$p['tier'])) ?></span>
                        <div class="text-muted small">KSh <?= number_format((float)$p['monthly_fee'], 0) ?>/mo</div>
                    </td>
                    <td>
                        KSh <?= number_format((float)$p['setup_fee'], 0) ?>
                        <div>
                        <?php if ((int)$p['setup_fee_paid'] === 1): ?>
                            <span class="badge bg-success">Paid</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Due</span>
                        <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?= $p['plan_expires_at'] ? e(date('d M Y', strtotime((string)$p['plan_expires_at']))) : 'never' ?>
                        <div class="small <?= $daysLeft !== null && $daysLeft <= 14 ? 'text-danger fw-semibold' : 'text-muted' ?>">
                            <?= $daysLeft === null ? '' : ($daysLeft >= 0 ? $daysLeft . ' days left' : 'expired ' . abs($daysLeft) . 'd ago') ?>
                        </div>
                    </td>
                    <td><span class="badge bg-<?= $p['status'] === 'active' ? 'success' : 'danger' ?>"><?= e(ucfirst((string)$p['status'])) ?></span></td>
                    <td class="text-end">
                        <form method="post" class="d-inline">
            <?= csrf_field() ?>
                            <input type="hidden" name="chama_id" value="<?= (int)$p['id'] ?>">
                            <?php if ((int)$p['setup_fee_paid'] === 0): ?>
                                <button name="action" value="setup_paid" class="btn btn-sm btn-success" title="Mark the one-off setup fee as collected">Fee paid</button>
                            <?php else: ?>
                                <button name="action" value="setup_unpaid" class="btn btn-sm btn-outline-warning" title="Mark the setup fee as outstanding again">Fee due</button>
                            <?php endif; ?>
                            <button name="action" value="extend" class="btn btn-sm btn-outline-secondary" title="Give them 30 more free days">+30d</button>
                            <button name="action" value="start_billing" class="btn btn-sm btn-outline-primary" title="End the pilot: subscription billing starts today">Bill now</button>
                            <button name="action" value="convert" class="btn btn-sm btn-outline-success" title="They paid: mark as a regular customer">Customer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-header">All groups: collection routes &amp; SMS credit</div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Group</th><th>Collection shortcode</th><th>Route</th><th>SMS units</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php
            $all = $pdo->query('SELECT id, name, pay_shortcode, pay_verified, sms_balance, tier FROM chamas ORDER BY name')->fetchAll();
            foreach ($all as $c): ?>
                <tr>
                    <td class="fw-semibold"><?= e($c['name']) ?></td>
                    <td><code><?= e($c['pay_shortcode'] !== '' ? $c['pay_shortcode'] : '-') ?></code></td>
                    <td>
                        <?php if ($c['pay_shortcode'] === ''): ?><span class="badge bg-secondary">Manual only</span>
                        <?php elseif ((int)$c['pay_verified'] === 1): ?><span class="badge bg-success">Verified</span>
                        <?php else: ?><span class="badge bg-warning text-dark">Awaiting verification</span><?php endif; ?>
                    </td>
                    <td><?= (int)$c['sms_balance'] ?></td>
                    <td class="text-end">
                        <form method="post" class="d-inline-flex gap-1 align-items-center justify-content-end">
            <?= csrf_field() ?>
                            <input type="hidden" name="chama_id" value="<?= (int)$c['id'] ?>">
                            <?php if ($c['pay_shortcode'] !== ''): ?>
                                <?php if ((int)$c['pay_verified'] === 1): ?>
                                    <button name="action" value="unverify_route" class="btn btn-sm btn-outline-warning">Unverify</button>
                                <?php else: ?>
                                    <button name="action" value="verify_route" class="btn btn-sm btn-success" title="Confirm the group owns this shortcode (test payment checked)">Verify route</button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <input type="number" name="units" min="1" max="100000" value="100" style="width:80px" class="form-control form-control-sm">
                            <button name="action" value="sms_topup" class="btn btn-sm btn-outline-primary">+SMS</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-info mt-3 small">
    <i class="fa-solid fa-lightbulb me-1"></i>
    When a pilot's date passes, the group sees the normal "renew by M-Pesa" screen and managers keep
    billing access - exactly like any expired customer. "Bill now" simply moves that date to today.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
