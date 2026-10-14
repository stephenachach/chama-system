<?php
$page_title = 'Chamas';
require_once __DIR__ . '/../config/auth.php';
require_role(['superadmin']);
require_once __DIR__ . '/../includes/header.php';

$totalChamas = (int)$pdo->query('SELECT COUNT(*) FROM chamas')->fetchColumn();
$activeChamas = (int)$pdo->query("SELECT COUNT(*) FROM chamas WHERE status = 'active'")->fetchColumn();
$totalMembers = (int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
$platformRevenue = (float)$pdo->query("SELECT COALESCE(SUM(monthly_fee),0) FROM chamas WHERE status = 'active'")->fetchColumn();

$chamas = $pdo->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM members m WHERE m.chama_id = c.id) AS member_count,
        (SELECT COALESCE(SUM(amount),0) FROM contributions ct WHERE ct.chama_id = c.id) AS total_contributions
    FROM chamas c ORDER BY c.id DESC
")->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Chamas</h2>
            <div class="text-muted">Every group on the platform. Each chama is fully isolated from the others.</div>
        </div>
        <a href="<?= url('chamas/create.php') ?>" class="btn btn-primary btn-lg">New Chama</a>
    </div>
</div>

<div class="row row-cols-1 row-cols-md-4 g-3 mb-4">
    <div class="col"><div class="card stat-card bg-primary"><div class="card-body"><div class="icon">🏘️</div><h6 class="mb-2">Total Chamas</h6><h2><?= e($totalChamas) ?></h2></div></div></div>
    <div class="col"><div class="card stat-card bg-success"><div class="card-body"><div class="icon">✅</div><h6 class="mb-2">Active</h6><h2><?= e($activeChamas) ?></h2></div></div></div>
    <div class="col"><div class="card stat-card bg-info"><div class="card-body"><div class="icon">👥</div><h6 class="mb-2">All Members</h6><h2><?= e($totalMembers) ?></h2></div></div></div>
    <div class="col"><div class="card stat-card bg-warning"><div class="card-body"><div class="icon">📈</div><h6 class="mb-2">Monthly Revenue</h6><h2><?= money($platformRevenue) ?></h2></div></div></div>
</div>

<div class="card shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Name</th><th>Members</th><th>Total Contributions</th><th>Plan</th><th>Monthly Fee</th><th>Status</th><th>Created</th><th width="210">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($chamas as $c): ?>
                <tr>
                    <td><strong><?= e($c['name']) ?></strong><br><small class="text-muted">/<?= e($c['slug']) ?></small></td>
                    <td><?= e($c['member_count']) ?></td>
                    <td><?= money($c['total_contributions']) ?></td>
                    <td><span class="badge bg-<?= $c['plan'] === 'pro' ? 'primary' : 'secondary' ?>"><?= e(ucfirst($c['plan'])) ?></span></td>
                    <td><?= money($c['monthly_fee']) ?></td>
                    <td><span class="badge bg-<?= $c['status'] === 'active' ? 'success' : 'danger' ?>"><?= e(ucfirst($c['status'])) ?></span></td>
                    <td><?= e(substr((string)$c['created_at'], 0, 10)) ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline-primary" href="<?= url('chamas/view.php?id=' . $c['id']) ?>">View</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= url('chamas/edit.php?id=' . $c['id']) ?>">Edit</a>
                        <?php if ($c['status'] === 'active'): ?>
                            <a class="btn btn-sm btn-outline-danger" onclick="return confirm('Suspend this group? Its users will no longer be able to log in.')" href="<?= url('chamas/edit.php?id=' . $c['id'] . '&status=suspended') ?>">Suspend</a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline-success" href="<?= url('chamas/edit.php?id=' . $c['id'] . '&status=active') ?>">Activate</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$chamas): ?><tr><td colspan="8" class="text-center text-muted">No chamas yet. Create the first one.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
