<?php
$page_title = 'Welfare Payouts';
require_once __DIR__ . '/../config/auth.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/audit.php';

// Save the large-payout threshold (POST, before any output).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_threshold')) {
    $t = max(0, round((float)($_POST['welfare_threshold'] ?? 0), 2));
    $pdo->prepare('UPDATE chamas SET welfare_threshold = ? WHERE id = ?')->execute([$t, current_chama_id()]);
    audit_log('welfare.threshold', 'chama', current_chama_id(), $t > 0 ? 'Large-payout second approval set at KSh ' . number_format($t, 2) : 'Large-payout second approval turned off');
    flash_success($t > 0 ? 'Saved: payouts of ' . money($t) . ' and above now need a second approval.' : 'Saved: second approval requirement turned off.');
    header('Location: ' . url('welfare/index.php'));
    exit;
}

require_once __DIR__ . '/../includes/header.php';
$status = $_GET['status'] ?? '';
$sql = "SELECT w.*, m.member_no, m.full_name FROM welfare_payouts w JOIN members m ON m.id=w.member_id WHERE w.chama_id=?";
$params = [current_chama_id()];
if ($status) { $sql .= " AND w.status=?"; $params[] = $status; }
$sql .= " ORDER BY w.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payouts = $stmt->fetchAll();
$threshold = (float)(current_chama()['welfare_threshold'] ?? 0);
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
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-lg-7">
                <div class="eyebrow mb-1">Large-payout protection</div>
                <p class="mb-0 text-muted small">When a payout is <strong>at or above</strong> this amount, a second officer (someone other than the approver) must confirm it before it can be marked paid. Set 0 to turn this off.</p>
            </div>
            <div class="col-lg-5">
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_threshold">
                    <div class="col-7"><input type="number" step="0.01" min="0" class="form-control" name="welfare_threshold" value="<?= e(number_format($threshold, 2, '.', '')) ?>" inputmode="decimal" aria-label="Payout threshold in KSh"></div>
                    <div class="col-5 d-grid"><button class="btn btn-outline-primary">Save threshold</button></div>
                </form>
                <div class="form-text">Current: <?= $threshold > 0 ? money($threshold) . ' and above needs 2 approvals' : 'off' ?></div>
            </div>
        </div>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <form method="get" class="row g-2 mb-3 no-print"><div class="col-md-4"><select class="form-select" name="status"><option value="">All Statuses</option><?php foreach(['pending','approved','rejected','paid'] as $s): ?><option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div><div class="col-md-2 d-grid"><button class="btn btn-outline-primary">Filter</button></div></form>
        <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Member</th><th>Request Date</th><th>Amount</th><th>Reason</th><th>Status</th><th>Approved Date</th><th width="220">Actions</th></tr></thead><tbody>
        <?php foreach($payouts as $w): ?><tr><td><?= e($w['member_no'].' - '.$w['full_name']) ?></td><td><?= e($w['request_date']) ?></td><td><?= money($w['amount']) ?></td><td><?= e($w['reason']) ?><br><small class="text-muted"><?= e($w['notes']) ?></small></td><td><span class="badge bg-<?= ['pending'=>'secondary','approved'=>'success','rejected'=>'danger','paid'=>'primary'][$w['status']] ?>"><?= e(ucfirst($w['status'])) ?></span><?php if ((int)($w['needs_second'] ?? 0) === 1): ?> <span class="badge bg-<?= (int)$w['second_ok'] === 1 ? 'success' : 'warning text-dark' ?>"><?= (int)$w['second_ok'] === 1 ? '2nd OK' : 'Needs 2nd' ?></span><?php endif; ?></td><td><?= e($w['approved_date']) ?></td><td><?php if($w['status']==='pending'): ?><a class="btn btn-sm btn-outline-success" href="<?= url('welfare/approve.php?id='.$w['id']) ?>">Approve/Reject</a><?php endif; ?> <?php if($w['status']==='approved' && (int)($w['needs_second'] ?? 0) === 1 && (int)$w['second_ok'] !== 1): ?><form method="post" action="<?= url('welfare/second_confirm.php') ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><button class="btn btn-sm btn-warning" onclick="return confirm('Confirm you have reviewed this large payout?')">Second approval</button></form><?php endif; ?> <?php if($w['status']==='approved'): ?><form method="post" action="<?= url('welfare/mark_paid.php') ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><button class="btn btn-sm btn-outline-primary">Mark Paid</button></form><?php endif; ?> <form method="post" action="<?= url('welfare/delete.php') ?>" class="d-inline" onsubmit="return confirm('Delete this welfare request?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr><?php endforeach; ?>
        <?php if(!$payouts): ?><tr><td colspan="7" class="text-center text-muted">No welfare records found.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
