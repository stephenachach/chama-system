<?php
$page_title = 'New Chama';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['superadmin']);
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $billing_phone = trim($_POST['billing_phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $plan = $_POST['plan'] ?? 'free';
    $monthly_fee = (float)($_POST['monthly_fee'] ?? 0);
    $monthly_dues = (float)($_POST['monthly_dues'] ?? 0);
    $trial_days = max(0, min(365, (int)($_POST['trial_days'] ?? 30)));
    $started_at = $_POST['started_at'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    $admin_name = trim($_POST['admin_name'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_password = $_POST['admin_password'] ?? '';

    $error = '';
    if ($name === '') {
        $error = 'Chama name is required.';
    } elseif (!in_array($plan, ['free', 'pro'], true)) {
        $error = 'Invalid plan selected.';
    } elseif ($admin_name === '' || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'The first admin needs a name and a valid email address.';
    } elseif (strlen($admin_password) < 6) {
        $error = 'The admin password must be at least 6 characters long.';
    } else {
        try {
            $pdo->beginTransaction();

            // Build a unique slug.
            $baseSlug = slugify($name);
            $slug = $baseSlug;
            $n = 2;
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM chamas WHERE slug = ?');
            while (true) {
                $stmt->execute([$slug]);
                if ((int)$stmt->fetchColumn() === 0) break;
                $slug = $baseSlug . '-' . $n;
                $n++;
            }

            $monthly_fee = max(0, $monthly_fee);
            // A paid plan starts its clock immediately (after any free trial days).
            // A free plan with no fee never expires.
            $planExpiresAt = null;
            if ($monthly_fee > 0) {
                $planExpiresAt = $trial_days > 0
                    ? date('Y-m-d', strtotime('+' . $trial_days . ' days -1 day'))
                    : date('Y-m-d', strtotime('+1 month -1 day'));
            }

            $stmt = $pdo->prepare("INSERT INTO chamas (name, slug, phone, billing_phone, email, plan, monthly_fee, monthly_dues, plan_expires_at, status, started_at, notes) VALUES (?,?,?,?,?,?,?,?,?, 'active', ?, ?)");
            $stmt->execute([$name, $slug, $phone, $billing_phone !== '' ? $billing_phone : $phone, $email, $plan, $monthly_fee, max(0, $monthly_dues), $planExpiresAt, $started_at !== '' ? $started_at : null, $notes]);
            $chamaId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO users (chama_id, member_id, name, email, password, role, account_status) VALUES (?, NULL, ?, ?, ?, 'admin', 'approved')");
            $stmt->execute([$chamaId, $admin_name, $admin_email, password_hash($admin_password, PASSWORD_DEFAULT)]);

            $pdo->commit();
            $expiryNote = $planExpiresAt ? ' Subscription runs to ' . date('d M Y', strtotime($planExpiresAt)) . '.' : '';
            flash_success("Chama “{$name}” created. Its admin can log in with {$admin_email} and the password you set. Public registration link: " . url('register.php?chama=' . $slug) . $expiryNote);
            audit_log('chama.create', 'chama', (int)$pdo->lastInsertId(), sprintf('Created chama %s (%s plan, fee KSh %s)', $name, $plan, number_format($monthly_fee, 2)));
            header('Location: ' . url('chamas/index.php'));
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_error('Could not create the chama. The admin email may already be in use.');
        }
    }

    if ($error) {
        flash_error($error);
    }
}
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">New Chama</h2>
            <div class="text-muted">Set up a group and its first admin. The group's members and data stay fully separate from every other group.</div>
        </div>
        <a href="<?= url('chamas/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Chamas</a>
    </div>
</div>
<div class="card shadow-sm mx-auto" style="max-width: 850px;">
    <div class="card-header">Chama Details</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-6"><label class="form-label required">Chama Name</label><input class="form-control" name="name" value="<?= e($_POST['name'] ?? '') ?>" required></div>
            <div class="col-md-3"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e($_POST['phone'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= e($_POST['email'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Billing phone (M-Pesa)</label><input class="form-control" name="billing_phone" value="<?= e($_POST['billing_phone'] ?? '') ?>" placeholder="0712 345 678">
                <div class="form-text">Receives the STK prompt. Defaults to the phone above.</div></div>
            <div class="col-md-3"><label class="form-label required">Plan</label>
                <select class="form-select" name="plan">
                    <option value="free" <?= ($_POST['plan'] ?? 'free') === 'free' ? 'selected' : '' ?>>Free (no expiry)</option>
                    <option value="pro" <?= ($_POST['plan'] ?? '') === 'pro' ? 'selected' : '' ?>>Pro (paid monthly)</option>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">Monthly Fee (KES)</label><input type="number" step="0.01" min="0" class="form-control" name="monthly_fee" value="<?= e($_POST['monthly_fee'] ?? '500') ?>">
                <div class="form-text">0 = never billed or suspended.</div></div>
            <div class="col-md-3"><label class="form-label">Member dues (KES/mo)</label><input type="number" step="0.01" min="0" class="form-control" name="monthly_dues" value="<?= e($_POST['monthly_dues'] ?? '0') ?>">
                <div class="form-text">What each member pays this chama.</div></div>
            <div class="col-md-3"><label class="form-label">Free trial (days)</label><input type="number" min="0" max="365" class="form-control" name="trial_days" value="<?= e($_POST['trial_days'] ?? '30') ?>">
                <div class="form-text">Billing starts after these days.</div></div>
            <div class="col-md-3"><label class="form-label">Started On</label><input type="date" class="form-control" name="started_at" value="<?= e($_POST['started_at'] ?? '') ?>"></div>
            <div class="col-md-9"><label class="form-label">Notes</label><input class="form-control" name="notes" value="<?= e($_POST['notes'] ?? '') ?>"></div>

            <hr>
            <div class="col-12"><h6 class="text-muted">First Admin (created automatically)</h6></div>
            <div class="col-md-4"><label class="form-label required">Admin Name</label><input class="form-control" name="admin_name" value="<?= e($_POST['admin_name'] ?? '') ?>" required></div>
            <div class="col-md-4"><label class="form-label required">Admin Email</label><input type="email" class="form-control" name="admin_email" value="<?= e($_POST['admin_email'] ?? '') ?>" required></div>
            <div class="col-md-4"><label class="form-label required">Admin Password</label><input type="password" class="form-control" name="admin_password" minlength="6" required></div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Create Chama</button>
                <a class="btn btn-secondary" href="<?= url('chamas/index.php') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
