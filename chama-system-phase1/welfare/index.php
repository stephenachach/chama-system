<?php
$page_title = 'Welfare Payouts';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';
$status = $_GET['status'] ?? '';
$sql = "SELECT w.*, m.member_no, m.full_name FROM welfare_payouts w JOIN members m ON m.id=w.member_id WHERE w.chama_id=?";
$params = [current_chama_id()];
if ($status) { $sql .= " AND w.status=?"; $params[] = $status; }
$sql .= " ORDER BY w.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payouts = $stmt->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Welfare Payouts</h2>
            <div class="text-muted">Manage emergency, bereavement, medical and other welfare support.</div>
        </div>
        <a href="<?= url('welfare/create.php') ?>" class="btn btn-primary btn-lg">New Welfare Request</a>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <form method="get" class="row g-2 mb-3 no-print"><div class="col-md-4"><select class="form-select" name="status"><option value="">All Statuses</option><?php foreach(['pending','approved','rejected','paid'] as $s): ?><option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div><div class="col-md-2 d-grid"><button class="btn btn-outline-primary">Filter</button></div></form>
        <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Member</th><th>Request Date</th><th>Amount</th><th>Reason</th><th>Status</th><th>Approved Date</th><th width="220">Actions</th></tr></thead><tbody>
        <?php foreach($payouts as $w): ?><tr><td><?= e($w['member_no'].' - '.$w['full_name']) ?></td><td><?= e($w['request_date']) ?></td><td><?= money($w['amount']) ?></td><td><?= e($w['reason']) ?><br><small class="text-muted"><?= e($w['notes']) ?></small></td><td><span class="badge bg-<?= ['pending'=>'secondary','approved'=>'success','rejected'=>'danger','paid'=>'primary'][$w['status']] ?>"><?= e(ucfirst($w['status'])) ?></span></td><td><?= e($w['approved_date']) ?></td><td><?php if($w['status']==='pending'): ?><a class="btn btn-sm btn-outline-success" href="<?= url('welfare/approve.php?id='.$w['id']) ?>">Approve/Reject</a><?php endif; ?> <?php if($w['status']==='approved'): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('welfare/mark_paid.php?id='.$w['id']) ?>">Mark Paid</a><?php endif; ?> <a class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete welfare request?')" href="<?= url('welfare/delete.php?id='.$w['id']) ?>">Delete</a></td></tr><?php endforeach; ?>
        <?php if(!$payouts): ?><tr><td colspan="7" class="text-center text-muted">No welfare records found.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
