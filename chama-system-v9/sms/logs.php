<?php
/**
 * SMS log: everything the system tried to send, with delivery status.
 */
$page_title = 'SMS Log';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/sms.php';
require_login();
$user = current_user();
$isSuper = ($user['role'] ?? '') === 'superadmin';
require_role($isSuper ? ['superadmin'] : ['admin', 'treasurer']);

$chamaId = current_chama_id();
$where = [];
$params = [];
if (!$isSuper) {
    $where[] = 's.chama_id = ?';
    $params[] = $chamaId;
}
if (($purpose = (string)($_GET['purpose'] ?? '')) !== '') {
    $where[] = 'purpose = ?';
    $params[] = $purpose;
}
if (($status = (string)($_GET['status'] ?? '')) !== '') {
    $where[] = 'status = ?';
    $params[] = $status;
}
if (($q = trim((string)($_GET['q'] ?? ''))) !== '') {
    $where[] = '(phone LIKE ? OR message LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$sql = 'FROM sms_logs s LEFT JOIN members m ON m.id = s.member_id LEFT JOIN chamas c ON c.id = s.chama_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

$stmt = $pdo->prepare("SELECT COUNT(*) $sql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$stmt = $pdo->prepare("SELECT s.*, m.full_name, m.member_no, c.name AS chama_name $sql ORDER BY s.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
$badge = ['sent' => 'success', 'simulated' => 'info', 'failed' => 'danger', 'queued' => 'warning'];
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1"><i class="fa-solid fa-list-check me-2"></i>SMS Log</h2>
            <div class="text-muted"><?= e(number_format($total)) ?> message(s) recorded. Simulation entries cost nothing.</div>
        </div>
        <a href="<?= url('sms/index.php') ?>" class="btn btn-outline-primary btn-lg">Compose</a>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label">Purpose</label>
                <select name="purpose" class="form-select">
                    <option value="">All</option>
                    <?php foreach (['receipt', 'reminder', 'broadcast', 'loan', 'notice'] as $p): ?>
                    <option value="<?= $p ?>" <?= $purpose === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <?php foreach (['sent', 'simulated', 'failed', 'queued'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-8 col-md-4">
                <label class="form-label">Search phone / text</label>
                <input type="search" name="q" value="<?= e($q) ?>" class="form-control">
            </div>
            <div class="col-4 col-md-2 d-grid"><button class="btn btn-primary">Filter</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>When</th>
                    <?php if ($isSuper): ?><th>Chama</th><?php endif; ?>
                    <th>Member</th>
                    <th>Phone</th>
                    <th>Purpose</th>
                    <th class="w-50">Message</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $isSuper ? 7 : 6 ?>" class="text-muted py-3">No messages yet. Compose one, or wait for the first payment receipt.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="text-nowrap"><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
                    <?php if ($isSuper): ?><td><?= e($r['chama_name'] ?? '-') ?></td><?php endif; ?>
                    <td><?= e($r['full_name'] ?? '-') ?></td>
                    <td class="text-nowrap"><?= e($r['phone']) ?></td>
                    <td><span class="badge bg-secondary"><?= e(ucfirst((string)$r['purpose'])) ?></span></td>
                    <td>
                        <?= e($r['message']) ?>
                        <?php if ($r['error'] !== ''): ?><div class="text-danger small"><?= e($r['error']) ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?= $badge[$r['status']] ?? 'secondary' ?>"><?= e(ucfirst((string)$r['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="text-muted small">Page <?= $page ?> of <?= $pages ?></span>
        <div>
            <?php $qs = fn($p) => url('sms/logs.php') . '?' . http_build_query(array_merge($_GET, ['page' => $p])); ?>
            <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e($qs($page - 1)) ?>">Prev</a>
            <a class="btn btn-sm btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= e($qs($page + 1)) ?>">Next</a>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
