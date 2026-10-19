<?php
/**
 * Plans editor (superadmin): pricing is a hypothesis - change it here and the
 * landing page, signup form and member limits follow immediately.
 *
 * Business rules displayed on purpose:
 *  - groups pay the subscription; members never pay us for access
 *  - we never take a percentage of group money
 *  - SMS is prepaid, never "unlimited"
 */
$page_title = 'Plans & Pricing';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['superadmin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = trim((string)($_POST['plan_key'] ?? ''));
    $label = trim((string)($_POST['label'] ?? ''));
    $monthly = max(0, (float)($_POST['monthly'] ?? 0));
    $maxMembers = max(0, (int)($_POST['max_members'] ?? 0));
    $annualPay = max(1, min(12, (int)($_POST['annual_months_pay'] ?? 10)));
    $active = ((int)($_POST['active'] ?? 1)) === 1 ? 1 : 0;
    $description = trim((string)($_POST['description'] ?? ''));

    if ($key === '' || $label === '') {
        flash_error('Plan key and label are required.');
    } else {
        $before = $pdo->prepare('SELECT * FROM plans WHERE plan_key = ?');
        $before->execute([$key]);
        $old = $before->fetch();
        $pdo->prepare(
            'UPDATE plans SET label = ?, monthly = ?, max_members = ?, annual_months_pay = ?, active = ?, description = ? WHERE plan_key = ?'
        )->execute([$label, $monthly, $maxMembers, $annualPay, $active, str_cut($description, 255), $key]);
        audit_log('plan.update', 'plan', (int)($old['id'] ?? 0), sprintf('Plan %s: KSh %s/month, max %s members, annual pays %d months, %s', $key, number_format($monthly, 2), $maxMembers ?: 'unlimited', $annualPay, $active ? 'active' : 'hidden'), ['before' => $old ?: null]);
        flash_success('Plan "' . $label . '" saved. The public pricing page already shows the new numbers.');
    }
    header('Location: ' . url('billing/plans.php'));
    exit;
}

$allPlans = $pdo->query('SELECT plan_key, label, monthly, max_members, annual_months_pay, active, description FROM plans ORDER BY sort ASC, id ASC')->fetchAll();
$tiers = [];
foreach ($allPlans as $row) {
    $tiers[(string)$row['plan_key']] = [
        'label' => (string)$row['label'],
        'monthly' => (float)$row['monthly'],
        'max_members' => (int)$row['max_members'],
        'annual_months_pay' => (int)$row['annual_months_pay'],
        'active' => (int)$row['active'],
        'description' => (string)$row['description'],
    ];
}
$usage = [];
foreach ($tiers as $key => $t) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM chamas WHERE tier = ?");
    $stmt->execute([$key]);
    $usage[$key] = (int)$stmt->fetchColumn();
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1"><i class="fa-solid fa-tags me-2"></i>Plans &amp; Pricing</h2>
            <div class="text-muted">Launch hypotheses - edit freely. Changes apply everywhere at once.</div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('billing/pilots.php') ?>" class="btn btn-outline-warning btn-lg">Pilots</a>
            <a href="<?= url('billing/index.php') ?>" class="btn btn-outline-primary btn-lg">Billing</a>
        </div>
    </div>
</div>

<div class="alert alert-secondary small">
    <i class="fa-solid fa-scale-balanced me-1"></i>
    Rules this product keeps: the <strong>group</strong> pays the subscription (members never pay for access);
    we never take a percentage of group savings, contributions, welfare or loans; platform money and group
    money travel on separate M-Pesa routes; SMS is sold as prepaid credit, never unlimited.
</div>

<div class="row g-3">
    <?php foreach ($tiers as $key => $t): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <code><?= e($key) ?></code>
                <span class="badge bg-secondary"><?= (int)$usage[$key] ?> group(s)</span>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
            <?= csrf_field() ?>
                    <input type="hidden" name="plan_key" value="<?= e($key) ?>">
                    <div class="col-12">
                        <label class="form-label small mb-0">Label</label>
                        <input class="form-control form-control-sm" name="label" value="<?= e($t['label']) ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">KSh / month</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="monthly" value="<?= e($t['monthly']) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Max active members</label>
                        <input type="number" min="0" class="form-control form-control-sm" name="max_members" value="<?= (int)$t['max_members'] ?>">
                        <div class="form-text">0 = unlimited</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Annual: pay N months</label>
                        <input type="number" min="1" max="12" class="form-control form-control-sm" name="annual_months_pay" value="<?= (int)($t['annual_months_pay'] ?? 10) ?>">
                        <div class="form-text">for 12 months</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Visible</label>
                        <select class="form-select form-select-sm" name="active">
                            <option value="1" <?= (int)($t['active'] ?? 1) === 1 ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= (int)($t['active'] ?? 1) === 0 ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">One-line description</label>
                        <input class="form-control form-control-sm" name="description" value="<?= e($t['description'] ?? '') ?>">
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-sm btn-primary">Save <?= e($key) ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="alert alert-info mt-3 small">
    <i class="fa-solid fa-lightbulb me-1"></i>
    Member limits are enforced by the server when a member is created, re-activated or approved -
    not just shown on this page. Annual price is computed as monthly x "pay N months".
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
