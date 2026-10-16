<?php
$page_title = 'Edit Member';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Edit Member</h2>
            <div class="text-muted">Update member details cleanly and quickly.</div>
        </div>
        <a href="<?= url('members/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Members</a>
    </div>
</div>
<?php
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM members WHERE id=? AND chama_id=?');
$stmt->execute([$id, current_chama_id()]);
$member = $stmt->fetch();
if (!$member) { flash_error('Member not found.'); header('Location: ' . url('members/index.php')); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare('UPDATE members SET member_no=?, full_name=?, phone=?, email=?, address=?, join_date=?, status=? WHERE id=?');
    try {
        $stmt->execute([trim($_POST['member_no']), trim($_POST['full_name']), trim($_POST['phone']), trim($_POST['email']), trim($_POST['address']), $_POST['join_date'], $_POST['status'], $id]);
        audit_log('member.update', 'member', $id, sprintf('Updated member %s (%s), status %s', trim($_POST['full_name']), trim($_POST['member_no']), $_POST['status']), ['before' => ['status' => $member['status'], 'phone' => $member['phone']]]);
        flash_success('Member updated successfully.');
        header('Location: ' . url('members/index.php')); exit;
    } catch (PDOException $e) { flash_error('Could not update member.'); }
}
?>
<div class="card shadow-sm mx-auto" style="max-width: 850px;">
    <div class="card-header">Edit Member</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-4"><label class="form-label required">Member No</label><input class="form-control" name="member_no" value="<?= e($member['member_no']) ?>" required></div>
            <div class="col-md-8"><label class="form-label required">Full Name</label><input class="form-control" name="full_name" value="<?= e($member['full_name']) ?>" required></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e($member['phone']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= e($member['email']) ?>"></div>
            <div class="col-md-6"><label class="form-label required">Join Date</label><input class="form-control" type="date" name="join_date" value="<?= e($member['join_date']) ?>" required></div>
            <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active" <?= $member['status']==='active'?'selected':'' ?>>Active</option><option value="inactive" <?= $member['status']==='inactive'?'selected':'' ?>>Inactive</option></select></div>
            <div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="address"><?= e($member['address']) ?></textarea></div>
            <div class="col-12"><button class="btn btn-primary">Update Member</button> <a class="btn btn-secondary" href="<?= url('members/index.php') ?>">Cancel</a></div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
