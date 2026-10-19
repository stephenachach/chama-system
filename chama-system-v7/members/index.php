<?php
$page_title = 'Members';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';
$chamaId = current_chama_id();
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE chama_id = ? AND (full_name LIKE ? OR member_no LIKE ? OR phone LIKE ?) ORDER BY id DESC");
    $like = "%{$q}%";
    $stmt->execute([$chamaId, $like, $like, $like]);
    $members = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT * FROM members WHERE chama_id = ? ORDER BY id DESC');
    $stmt->execute([$chamaId]);
    $members = $stmt->fetchAll();
}
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Members</h2>
            <div class="text-muted">Register and manage chama members in one place.</div>
        </div>
        <a href="<?= url('members/create.php') ?>" class="btn btn-primary btn-lg">Add Member</a>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <form class="row g-2 mb-3 no-print" method="get">
            <div class="col-md-10"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search by name, member number or phone"></div>
            <div class="col-md-2 d-grid"><button class="btn btn-outline-primary">Search</button></div>
        </form>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Member No</th><th>Name</th><th>Phone</th><th>Email</th><th>Join Date</th><th>Status</th><th width="160">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($members as $m): ?>
                    <tr>
                        <td><?= e($m['member_no']) ?></td><td><?= e($m['full_name']) ?></td><td><?= e($m['phone']) ?></td><td><?= e($m['email']) ?></td><td><?= e($m['join_date']) ?></td>
                        <td><span class="badge bg-<?= $m['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst($m['status'])) ?></span></td>
                        <td><a class="btn btn-sm btn-outline-success" title="PDF statement" href="<?= url('members/statement.php?id=' . $m['id']) ?>"><i class="fa-solid fa-file-pdf"></i> Statement</a> <a class="btn btn-sm btn-outline-primary" href="<?= url('members/edit.php?id=' . $m['id']) ?>">Edit</a> <form method="post" action="<?= url('members/delete.php') ?>" class="d-inline" onsubmit="return confirm('Delete this member?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$members): ?><tr><td colspan="7" class="text-center text-muted">No members found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
