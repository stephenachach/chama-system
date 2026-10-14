<?php
require_once __DIR__ . '/config/auth.php';

if (is_logged_in()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$error = '';
$success = '';

// Which chama is this registration for?
// A per-chama link looks like: register.php?chama=acme-chama
$chamaSlug = trim($_GET['chama'] ?? ($_POST['chama_slug'] ?? ''));
$activeChamas = $pdo->query("SELECT id, name, slug FROM chamas WHERE status = 'active' ORDER BY name")->fetchAll();

$selectedChama = null;
if ($chamaSlug !== '') {
    $stmt = $pdo->prepare("SELECT id, name, slug FROM chamas WHERE slug = ? AND status = 'active'");
    $stmt->execute([$chamaSlug]);
    $selectedChama = $stmt->fetch();
    if (!$selectedChama) {
        $error = 'That registration link is not active. Ask your chama admin for the correct link.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$full_name || !$phone || !$email || !$password) {
        $error = 'Full name, phone, email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Password and confirm password do not match.';
    } else {
        // Determine the target chama.
        $chama = null;
        if ($selectedChama) {
            $chama = $selectedChama;
        } else {
            $chamaId = (int)($_POST['chama_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, name, slug FROM chamas WHERE id = ? AND status = 'active'");
            $stmt->execute([$chamaId]);
            $chama = $stmt->fetch();
        }
        if (!$chama) {
            $error = 'Please choose which group you are registering with.';
        }
    }

    if (!$error) {
        try {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $check->execute([$email]);

            if ($check->fetch()) {
                $error = 'That email address is already registered.';
            } else {
                $pdo->beginTransaction();

                // Temporary unique member number. Admin/treasurer can edit later if needed.
                $member_no = 'M' . date('ymdHis');

                $stmt = $pdo->prepare('SELECT COUNT(*) FROM members WHERE chama_id = ? AND member_no = ?');
                $stmt->execute([(int)$chama['id'], $member_no]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $member_no .= substr(md5(uniqid('', true)), 0, 4);
                }

                $stmt = $pdo->prepare('INSERT INTO members (chama_id, member_no, full_name, phone, email, address, join_date, status) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([(int)$chama['id'], $member_no, $full_name, $phone, $email, $address, date('Y-m-d'), 'inactive']);
                $member_id = $pdo->lastInsertId();

                $stmt = $pdo->prepare('INSERT INTO users (chama_id, member_id, name, email, password, role, account_status) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([
                    (int)$chama['id'],
                    $member_id,
                    $full_name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    'member',
                    'pending'
                ]);

                $pdo->commit();
                $success = 'Registration submitted successfully to ' . $chama['name'] . '. Please wait for admin or treasurer approval before logging in.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Registration failed. Please try again or contact the administrator.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Member Registration - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="card shadow-lg" style="max-width: 760px; width: 100%;">
        <div class="card-body p-4">
            <h3 class="fw-bold text-center mb-2">Member Registration</h3>
            <?php if ($selectedChama): ?>
                <p class="text-center mb-1"><span class="badge bg-primary fs-6"><?= e($selectedChama['name']) ?></span></p>
                <p class="text-muted text-center mb-4">You are registering with this group. Your account will be pending until approved by its admin or treasurer.</p>
            <?php else: ?>
                <p class="text-muted text-center mb-4">Choose your group, then register as a member. Your account will be pending until approved by admin or treasurer.</p>
            <?php endif; ?>

            <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

            <?php if (!$activeChamas): ?>
                <div class="alert alert-warning">No group is currently open for registration. Please contact your chama administrator.</div>
            <?php else: ?>
            <form method="post" class="row g-3">
                <?php if ($selectedChama): ?>
                    <input type="hidden" name="chama_id" value="<?= (int)$selectedChama['id'] ?>">
                    <input type="hidden" name="chama_slug" value="<?= e($selectedChama['slug']) ?>">
                <?php else: ?>
                    <div class="col-12">
                        <label class="form-label required">Your Group</label>
                        <select class="form-select" name="chama_id" required>
                            <option value="">Select your group</option>
                            <?php foreach ($activeChamas as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= (int)($_POST['chama_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label required">Full Name</label>
                    <input class="form-control" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Phone Number</label>
                    <input class="form-control" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" required>
                </div>
                <div class="col-md-12">
                    <label class="form-label required">Email Address</label>
                    <input type="email" class="form-control" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Password</label>
                    <input type="password" class="form-control" name="password" minlength="6" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Confirm Password</label>
                    <input type="password" class="form-control" name="confirm_password" minlength="6" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="address" rows="2"><?= e($_POST['address'] ?? '') ?></textarea>
                </div>
                <div class="col-12 d-grid gap-2">
                    <button class="btn btn-primary" type="submit">Submit Registration</button>
                    <a class="btn btn-outline-secondary" href="<?= url('index.php') ?>">Back to Login</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
