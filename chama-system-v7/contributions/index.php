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

// M-Pesa requests still waiting for the customer's PIN / the callback.
$pendingStmt = $pdo->prepare(
    "SELECT p.*, m.full_name, m.member_no FROM mpesa_payments p
       JOIN members m ON m.id = p.member_id
      WHERE p.chama_id = ? AND p.purpose = 'contribution' AND p.status = 'pending'
      ORDER BY p.id DESC LIMIT 10"
);
$pendingStmt->execute([$chamaId]);
$pendingPayments = $pendingStmt->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Contributions</h2>
            <div class="text-muted">Track all contributions with a clean, simple view.</div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('contributions/pay.php') ?>" class="btn btn-success btn-lg"><i class="fa-solid fa-mobile-screen me-1"></i>Collect by M-Pesa</a>
            <a href="<?= url('contributions/create.php') ?>" class="btn btn-primary btn-lg">New Contribution</a>
        </div>
    </div>
</div>

<?php if ($pendingPayments): ?>
<div class="card shadow-sm mb-4 border-warning">
    <div class="card-header bg-warning-subtle">M-Pesa requests awaiting confirmation</div>
    <div class="card-body table-responsive p-0">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Member</th><th class="text-end">Amount</th><th>Phone</th><th>Sent</th><th>Reference</th></tr></thead>
            <tbody>
            <?php foreach ($pendingPayments as $p): ?>
                <tr>
                    <td><?= e($p['full_name']) ?> <small class="text-muted">(<?= e($p['member_no']) ?>)</small></td>
                    <td class="text-end"><?= money($p['amount']) ?></td>
                    <td><?= mpesa_mask_phone((string)$p['phone']) ?></td>
                    <td><?= e(date('d M H:i', strtotime((string)$p['created_at']))) ?></td>
                    <td><code class="small"><?= e($p['checkout_request_id']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
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
                    <tr><td><?= e($c['contribution_date']) ?></td><td><?= e($c['member_no'].' - '.$c['full_name']) ?></td><td><?= e(ucfirst($c['type'])) ?><?php if ($c['type'] === 'mpesa'): ?> <span class="badge bg-success">M-Pesa</span><?php endif; ?></td><td><code><?= e($c['reference']) ?></code></td><td><?= e($c['notes']) ?></td><td><?= money($c['amount']) ?></td><td><form method="post" action="<?= url('contributions/delete.php') ?>" class="d-inline" onsubmit="return confirm('Delete this contribution?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr>
                <?php endforeach; ?>
                <?php if (!$contributions): ?><tr><td colspan="7" class="text-center text-muted">No contributions found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
