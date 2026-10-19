<?php
$page_title = 'Add Member';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin','treasurer']);
require_once __DIR__ . '/../includes/header.php';

?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">Add Member</h2>
            <div class="text-muted">Create a new member record with minimal data entry.</div>
        </div>
        <a href="<?= url('members/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Members</a>
    </div>
</div>
<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_no = trim($_POST['member_no'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $join_date = $_POST['join_date'] ?? date('Y-m-d');
    $status = $_POST['status'] ?? 'active';
    $slot = plan_member_slot_available(current_chama() ?: []);

    if ($member_no && $full_name && $join_date) {
        $chamaId = current_chama_id();
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM members WHERE chama_id = ? AND member_no = ?');
            $stmt->execute([$chamaId, $member_no]);
            if ((int)$stmt->fetchColumn() > 0) {
                flash_error('That member number is already used in this group. Choose a different one.');
            } elseif ($status === 'active' && !$slot['ok']) {
                flash_error($slot['message']);
            } else {
                $stmt = $pdo->prepare('INSERT INTO members (chama_id, member_no, full_name, phone, email, address, join_date, status) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$chamaId, $member_no, $full_name, $phone, $email, $address, $join_date, $status]);
                audit_log('member.add', 'member', (int)$pdo->lastInsertId(), sprintf('Added member %s (%s)', $full_name, $member_no), ['phone' => $phone]);
                flash_success('Member added successfully.');
                header('Location: ' . url('members/index.php'));
                exit;
            }
        } catch (PDOException $e) { flash_error('Could not add member. Member number may already exist.'); }
    } else { flash_error('Member number, full name and join date are required.'); }
}
?>
<div class="card shadow-sm mx-auto" style="max-width: 850px;">
    <div class="card-header">Add Member</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-4"><label class="form-label required">Member No</label><input class="form-control" name="member_no" placeholder="M002" required></div>
            <div class="col-md-8"><label class="form-label required">Full Name</label><input class="form-control" name="full_name" required></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email"></div>
            <div class="col-md-6"><label class="form-label required">Join Date</label><input class="form-control" type="date" name="join_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            <div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="address"></textarea></div>
            <div class="col-12"><button class="btn btn-primary">Save Member</button> <a class="btn btn-secondary" href="<?= url('members/index.php') ?>">Cancel</a></div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
