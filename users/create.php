<?php
$page_title = 'Add User';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin']);
require_once __DIR__ . '/../includes/header.php';
$members = $pdo->query('SELECT id, member_no, full_name FROM members ORDER BY full_name')->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Add System User</h2>
            <div class="text-muted">Admin-only user creation. Use this page to add members, treasurers, or other admin accounts.</div>
        </div>
        <a href="<?= url('users/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Users</a>
    </div>
</div>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'member';
    $member_id = !empty($_POST['member_id']) ? (int)$_POST['member_id'] : null;
    if ($name && $email && strlen($password) >= 6) {
        try {
            $stmt = $pdo->prepare('INSERT INTO users (member_id, name, email, password, role, account_status) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$member_id, $name, $email, password_hash($password, PASSWORD_DEFAULT), $role, 'approved']);
            flash_success('User account created successfully.');
            header('Location: ' . url('users/index.php')); exit;
        } catch (PDOException $e) { flash_error('Could not create user. Email may already exist.'); }
    } else { flash_error('Name, email and password of at least 6 characters are required.'); }
}
?>
<div class="card shadow-sm mx-auto" style="max-width: 760px;"><div class="card-header">Add System User</div><div class="card-body"><form method="post" class="row g-3"><div class="col-md-6"><label class="form-label required">Name</label><input class="form-control" name="name" required></div><div class="col-md-6"><label class="form-label required">Email</label><input class="form-control" type="email" name="email" required></div><div class="col-md-6"><label class="form-label required">Password</label><input class="form-control" type="password" name="password" required minlength="6"></div><div class="col-12">
                    <label class="form-label required">Account Type</label>
                    <div class="btn-group" role="group" aria-label="Account type selection">
                        <input type="radio" class="btn-check" name="role" id="role_member" value="member" autocomplete="off" <?= ($_POST['role'] ?? 'member') === 'member' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="role_member">Member</label>

                        <input type="radio" class="btn-check" name="role" id="role_treasurer" value="treasurer" autocomplete="off" <?= ($_POST['role'] ?? '') === 'treasurer' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="role_treasurer">Treasurer</label>

                        <input type="radio" class="btn-check" name="role" id="role_admin" value="admin" autocomplete="off" <?= ($_POST['role'] ?? '') === 'admin' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="role_admin">Admin</label>
                    </div>
                    <div class="form-text">Choose Treasurer only when this user should approve loans and manage finance operations. Otherwise select Member.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Link to Member Record</label>
                    <select class="form-select" name="member_id">
                        <option value="">No member link</option>
                        <?php foreach($members as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= (!empty($_POST['member_id']) && $_POST['member_id']===$m['id']) ? 'selected' : '' ?>><?= e($m['member_no'].' - '.$m['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary">Create User</button>
                    <a class="btn btn-secondary" href="<?= url('users/index.php') ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
