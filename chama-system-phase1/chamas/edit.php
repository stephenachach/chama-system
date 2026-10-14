<?php
$page_title = 'Edit Chama';
require_once __DIR__ . '/../config/auth.php';
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
if (isset($_GET['status']) && in_array($_GET['status'], ['active', 'suspended'], true)) {
    $stmt = $pdo->prepare('UPDATE chamas SET status = ? WHERE id = ?');
    $stmt->execute([$_GET['status'], $id]);
    flash_success($chama['name'] . ' is now ' . $_GET['status'] . '.');
    header('Location: ' . url('chamas/index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $plan = $_POST['plan'] ?? 'free';
    $monthly_fee = (float)($_POST['monthly_fee'] ?? 0);
    $started_at = $_POST['started_at'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    if ($name === '' || !in_array($plan, ['free', 'pro'], true)) {
        flash_error('Chama name is required and plan must be valid.');
    } else {
        $stmt = $pdo->prepare('UPDATE chamas SET name = ?, phone = ?, email = ?, plan = ?, monthly_fee = ?, started_at = ?, notes = ? WHERE id = ?');
        $stmt->execute([$name, $phone, $email, $plan, max(0, $monthly_fee), $started_at !== '' ? $started_at : null, $notes, $id]);
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
<div class="card shadow-sm mx-auto" style="max-width: 850px;">
    <div class="card-header">Chama Details</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-6"><label class="form-label required">Chama Name</label><input class="form-control" name="name" value="<?= e($chama['name']) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e($chama['phone']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= e($chama['email']) ?>"></div>
            <div class="col-md-3"><label class="form-label required">Plan</label>
                <select class="form-select" name="plan">
                    <option value="free" <?= $chama['plan'] === 'free' ? 'selected' : '' ?>>Free</option>
                    <option value="pro" <?= $chama['plan'] === 'pro' ? 'selected' : '' ?>>Pro (paid)</option>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">Monthly Fee (KES)</label><input type="number" step="0.01" min="0" class="form-control" name="monthly_fee" value="<?= e($chama['monthly_fee']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Started On</label><input type="date" class="form-control" name="started_at" value="<?= e($chama['started_at'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Notes</label><input class="form-control" name="notes" value="<?= e($chama['notes']) ?>"></div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Save Changes</button>
                <a class="btn btn-secondary" href="<?= url('chamas/index.php') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
