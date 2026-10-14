<?php
$page_title = 'Contributions';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$member_id = (int)($_GET['member_id'] ?? 0);
$chamaId = current_chama_id();
$stmt = $pdo->prepare("SELECT id, member_no, full_name FROM members WHERE chama_id = ? ORDER BY full_name");
$stmt->execute([$chamaId]);
$members = $stmt->fetchAll();

$sql = "SELECT c.*, m.full_name, m.member_no FROM contributions c JOIN members m ON m.id=c.member_id WHERE c.chama_id = ?";
$params = [$chamaId];
if ($from) { $sql .= " AND c.contribution_date >= ?"; $params[] = $from; }
if ($to) { $sql .= " AND c.contribution_date <= ?"; $params[] = $to; }
if ($member_id) { $sql .= " AND c.member_id = ?"; $params[] = $member_id; }
$sql .= " ORDER BY c.contribution_date DESC, c.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contributions = $stmt->fetchAll();
$total = array_sum(array_column($contributions, 'amount'));
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Contributions</h2>
            <div class="text-muted">Track all contributions with a clean, simple view.</div>
        </div>
        <a href="<?= url('contributions/create.php') ?>" class="btn btn-primary btn-lg">New Contribution</a>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <form class="row g-2 mb-3 no-print" method="get">
            <div class="col-md-3"><select class="form-select" name="member_id"><option value="0">All Members</option><?php foreach($members as $m): ?><option value="<?= $m['id'] ?>" <?= $member_id===$m['id']?'selected':'' ?>><?= e($m['member_no'].' - '.$m['full_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><input type="date" name="from" value="<?= e($from) ?>" class="form-control"></div>
            <div class="col-md-3"><input type="date" name="to" value="<?= e($to) ?>" class="form-control"></div>
            <div class="col-md-3 d-grid"><button class="btn btn-outline-primary">Filter</button></div>
        </form>
        <div class="alert alert-success"><strong>Total Contributions:</strong> <?= money($total) ?></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Date</th><th>Member</th><th>Type</th><th>Reference</th><th>Notes</th><th>Amount</th><th width="90">Action</th></tr></thead>
                <tbody>
                <?php foreach ($contributions as $c): ?>
                    <tr><td><?= e($c['contribution_date']) ?></td><td><?= e($c['member_no'].' - '.$c['full_name']) ?></td><td><?= e(ucfirst($c['type'])) ?></td><td><?= e($c['reference']) ?></td><td><?= e($c['notes']) ?></td><td><?= money($c['amount']) ?></td><td><a class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this contribution?')" href="<?= url('contributions/delete.php?id='.$c['id']) ?>">Delete</a></td></tr>
                <?php endforeach; ?>
                <?php if (!$contributions): ?><tr><td colspan="7" class="text-center text-muted">No contributions found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
