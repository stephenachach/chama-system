<?php
$page_title = 'Chama Details';
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

$stmt = $pdo->prepare("SELECT COUNT(*) FROM members WHERE chama_id = ?");
$stmt->execute([$id]);
$memberCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE chama_id = ?");
$stmt->execute([$id]);
$totalContributions = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM loans WHERE chama_id = ? AND status = 'approved'");
$stmt->execute([$id]);
$loanBalance = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM welfare_payouts WHERE chama_id = ? AND status IN ('approved','paid')");
$stmt->execute([$id]);
$welfarePaid = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE chama_id = ? AND account_status = 'pending'");
$stmt->execute([$id]);
$pendingRegistrations = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT u.id, u.name, u.email, u.role, u.account_status, m.member_no FROM users u LEFT JOIN members m ON m.id = u.member_id WHERE u.chama_id = ? ORDER BY u.id DESC");
$stmt->execute([$id]);
$users = $stmt->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1"><?= e($chama['name']) ?></h2>
            <div class="text-muted">
                <span class="badge bg-<?= $chama['status'] === 'active' ? 'success' : 'danger' ?>"><?= e(ucfirst($chama['status'])) ?></span>
                <span class="badge bg-<?= $chama['plan'] === 'pro' ? 'primary' : 'secondary' ?>"><?= e(ucfirst($chama['plan'])) ?> · <?= money($chama['monthly_fee']) ?>/month</span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('chamas/edit.php?id=' . $id) ?>" class="btn btn-outline-primary">Edit</a>
            <a href="<?= url('chamas/index.php') ?>" class="btn btn-outline-secondary">Back to Chamas</a>
        </div>
    </div>
</div>

<div class="row row-cols-1 row-cols-md-5 g-3 mb-4">
    <div class="col"><div class="card stat-card bg-primary"><div class="card-body"><h6 class="mb-2">Members</h6><h2><?= e($memberCount) ?></h2></div></div></div>
    <div class="col"><div class="card stat-card bg-success"><div class="card-body"><h6 class="mb-2">Contributions</h6><h2><?= money($totalContributions) ?></h2></div></div></div>
    <div class="col"><div class="card stat-card bg-warning"><div class="card-body"><h6 class="mb-2">Loan Balance</h6><h2><?= money($loanBalance) ?></h2></div></div></div>
    <div class="col"><div class="card stat-card bg-danger"><div class="card-body"><h6 class="mb-2">Welfare Paid</h6><h2><?= money($welfarePaid) ?></h2></div></div></div>
    <div class="col"><div class="card stat-card bg-info"><div class="card-body"><h6 class="mb-2">Pending Signups</h6><h2><?= e($pendingRegistrations) ?></h2></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header">Group Info</div>
            <div class="card-body">
                <p class="mb-1"><strong>Registration link:</strong><br><code class="user-select-all"><?= e(url('register.php?chama=' . $chama['slug'])) ?></code></p>
                <p class="mb-1"><strong>Phone:</strong> <?= e($chama['phone'] ?: '—') ?></p>
                <p class="mb-1"><strong>Email:</strong> <?= e($chama['email'] ?: '—') ?></p>
                <p class="mb-1"><strong>Started:</strong> <?= e($chama['started_at'] ?: '—') ?></p>
                <p class="mb-0"><strong>Notes:</strong> <?= e($chama['notes'] ?: '—') ?></p>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header">Group Users</div>
            <div class="card-body table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Member No</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= e($u['name']) ?></td>
                            <td><?= e($u['email']) ?></td>
                            <td><span class="badge bg-primary"><?= e(ucfirst($u['role'])) ?></span></td>
                            <td><span class="badge bg-<?= ['pending' => 'secondary', 'approved' => 'success', 'rejected' => 'danger'][$u['account_status']] ?? 'secondary' ?>"><?= e(ucfirst($u['account_status'])) ?></span></td>
                            <td><?= e($u['member_no'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$users): ?><tr><td colspan="5" class="text-center text-muted">No users yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
