<?php
require_once __DIR__ . '/config/auth.php';
if (is_logged_in()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $accountStatus = $user['account_status'] ?? 'approved';
        if ($accountStatus === 'pending') {
            $error = 'Your account is still pending approval. Please wait for admin or treasurer approval.';
        } elseif ($accountStatus === 'rejected') {
            $error = 'Your registration was rejected. Please contact the administrator.';
        } else {
            // Resolve the user's chama (superadmin has none).
            $chamaId = isset($user['chama_id']) ? (int)$user['chama_id'] : null;
            $chamaName = '';
            if ($chamaId) {
                $stmt = $pdo->prepare('SELECT name, status FROM chamas WHERE id = ?');
                $stmt->execute([$chamaId]);
                $chama = $stmt->fetch();
                if (!$chama) {
                    $error = 'Your group is not available. Please contact the administrator.';
                } elseif ($chama['status'] !== 'active') {
                    $error = 'Your group is currently suspended. Please contact the administrator.';
                } else {
                    $chamaName = $chama['name'];
                }
            }
            if ($error === '') {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'member_id' => $user['member_id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'account_status' => $accountStatus,
                    'chama_id' => $chamaId,
                    'chama_name' => $chamaName,
                ];
                header('Location: ' . url('dashboard.php'));
                exit;
            }
        }
    } else {
        $error = 'Invalid email or password.';
    }
}
$page_title = 'Login';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= url('assets/css/style.css') ?>?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="row gx-4 gy-4 align-items-center w-100">
        <div class="col-lg-6">
            <div class="card shadow-lg welcome-card p-4 h-100 border-0">
                <div class="mb-4">
                    <div class="badge bg-soft-primary text-primary mb-3">Secure login</div>
                    <h1 class="display-6 fw-bold">Continue your work with <?= e(APP_NAME) ?></h1>
                    <p class="text-muted fs-5">Login to manage members, approve loans, and keep the chama running smoothly.</p>
                </div>
                <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
                <form method="post" class="row g-3">
                    <div class="col-12">
                        <label class="form-label required">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label required">Password</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-primary btn-lg">Login securely</button>
                    </div>
                </form>
                <div class="mt-4 text-center text-muted small">
                    New here? <a href="<?= url('register.php') ?>">Register as a member</a>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-lg welcome-card p-4 border-0 h-100">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <div class="badge bg-success text-white py-2 px-3">Fast access</div>
                    <div class="text-muted">Your dashboard awaits with real-time loan and member controls.</div>
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card feature-card p-3">
                            <h5 class="fw-bold">Modern Member Portal</h5>
                            <p class="mb-0 text-muted">User-first experience for members, treasurers, and admins.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="card feature-card p-3">
                            <h6 class="fw-semibold">Loan Management</h6>
                            <p class="mb-0 text-muted">Track applications, approvals, and repayments in one place.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="card feature-card p-3">
                            <h6 class="fw-semibold">Approval Workflow</h6>
                            <p class="mb-0 text-muted">Approve new members and loan requests from a single dashboard.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="card feature-card p-3">
                            <h6 class="fw-semibold">Transparent Records</h6>
                            <p class="mb-0 text-muted">Keep member and contribution history accessible and clear.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="card feature-card p-3">
                            <h6 class="fw-semibold">Secure Access</h6>
                            <p class="mb-0 text-muted">Built with strong authentication and role control.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
