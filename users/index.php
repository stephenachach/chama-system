<?php
$page_title = 'System Users';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin']);
require_once __DIR__ . '/../includes/header.php';
$stmt = $pdo->prepare('SELECT u.*, m.member_no FROM users u LEFT JOIN members m ON m.id=u.member_id WHERE u.chama_id = ? ORDER BY u.id DESC');
$stmt->execute([current_chama_id()]);
$users = $stmt->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">System Users</h2>
            <div class="text-muted">Manage login accounts and roles. Public registrations are approved from the Approvals page.</div>
        </div>
        <a class="btn btn-primary btn-lg" href="<?= url('users/create.php') ?>">Add User</a>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Account Status</th><th>Member No</th><th>Created</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach($users as $u): ?>
                <?php $status = $u['account_status'] ?? 'approved'; ?>
                <tr>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge bg-primary"><?= e(ucfirst($u['role'])) ?></span></td>
                    <td><span class="badge bg-<?= ['pending'=>'secondary','approved'=>'success','rejected'=>'danger'][$status] ?? 'secondary' ?>"><?= e(ucfirst($status)) ?></span></td>
                    <td><?= e($u['member_no']) ?></td>
                    <td><?= e($u['created_at']) ?></td>
                    <td>
                        <?php if ($u['role'] === 'member' && $status === 'approved'): ?>
                            <a class="btn btn-sm btn-warning" onclick="return confirm('Promote this member to treasurer?')" href="<?= url('users/promote.php?id=' . $u['id']) ?>">Promote</a>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
