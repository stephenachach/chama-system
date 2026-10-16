<?php
$page_title = 'My Account';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_login();
require_once __DIR__ . '/../includes/header.php';
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $hash = (string)$stmt->fetchColumn();

    if (!password_verify($current, $hash)) {
        flash_error('Your current password is incorrect.');
    } elseif (strlen($new) < 6) {
        flash_error('New password must be at least 6 characters long.');
    } elseif ($new !== $confirm) {
        flash_error('New password and confirm password do not match.');
    } else {
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        audit_log('user.password', 'user', (int)$user['id'], 'Password changed');
        flash_success('Your password has been changed successfully.');
        header('Location: ' . url('account/password.php'));
        exit;
    }
}
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="fw-bold mb-1">My Account</h2>
        <div class="text-muted">Update your login password.</div>
    </div>
</div>
<div class="card shadow-sm mx-auto" style="max-width: 560px;">
    <div class="card-header">Change Password</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12">
                <label class="form-label required">Current Password</label>
                <input type="password" class="form-control" name="current_password" required>
            </div>
            <div class="col-md-6">
                <label class="form-label required">New Password</label>
                <input type="password" class="form-control" name="new_password" minlength="6" required>
            </div>
            <div class="col-md-6">
                <label class="form-label required">Confirm New Password</label>
                <input type="password" class="form-control" name="confirm_password" minlength="6" required>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Update Password</button>
                <a class="btn btn-secondary" href="<?= url('dashboard.php') ?>">Back to Dashboard</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
