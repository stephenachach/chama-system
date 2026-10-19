<?php
/**
 * Audit log viewer: who changed what, when, from which IP.
 * Managers see their own group; the platform operator sees every group.
 */
$page_title = 'Audit Log';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_login();
$user = current_user();
$isSuper = ($user['role'] ?? '') === 'superadmin';
require_role($isSuper ? ['superadmin'] : ['admin', 'treasurer']);

$chamaId = current_chama_id();
$where = [];
$params = [];
if (!$isSuper) {
    $where[] = 'chama_id = ?';
    $params[] = $chamaId;
} else {
    if ($fc = (int)($_GET['chama'] ?? 0)) {
        $where[] = 'chama_id = ?';
        $params[] = $fc;
    }
}
if (($q = trim((string)($_GET['q'] ?? ''))) !== '') {
    $where[] = '(description LIKE ? OR actor LIKE ? OR action LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (($from = (string)($_GET['from'] ?? '')) !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if (($to = (string)($_GET['to'] ?? '')) !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
if (($entity = (string)($_GET['entity'] ?? '')) !== '') {
    $where[] = 'entity_type = ?';
    $params[] = $entity;
}
$sql = 'FROM audit_logs a LEFT JOIN chamas c ON c.id = a.chama_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

$stmt = $pdo->prepare("SELECT COUNT(*) $sql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 60;
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$stmt = $pdo->prepare("SELECT a.*, c.name AS chama_name $sql ORDER BY a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();

$entities = $pdo->query("SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type <> '' ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1"><i class="fa-solid fa-shield-halved me-2"></i>Audit Log</h2>
            <div class="text-muted">Every money, membership and settings change - append-only, <?= e(number_format($total)) ?> entries.</div>
        </div>
        <span class="badge bg-success fs-6">Tamper-evident: entries are only ever added</span>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <?php if ($isSuper): ?>
            <div class="col-6 col-md-3">
                <label class="form-label">Chama</label>
                <select name="chama" class="form-select">
                    <option value="0">All chamas</option>
                    <?php foreach ($pdo->query('SELECT id, name FROM chamas ORDER BY name')->fetchAll() as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)($_GET['chama'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-2">
                <label class="form-label">Area</label>
                <select name="entity" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($entities as $en): ?>
                    <option value="<?= e($en) ?>" <?= $entity === $en ? 'selected' : '' ?>><?= e(ucfirst((string)$en)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2"><label class="form-label">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control"></div>
            <div class="col-6 col-md-2"><label class="form-label">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control"></div>
            <div class="col-8 col-md-2"><label class="form-label">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control"></div>
            <div class="col-4 col-md-1 d-grid"><button class="btn btn-primary">Go</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Who</th>
                    <?php if ($isSuper): ?><th>Chama</th><?php endif; ?>
                    <th>Action</th>
                    <th class="w-50">What happened</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $isSuper ? 6 : 5 ?>" class="text-muted py-3">Nothing recorded yet. Actions you take from now on will appear here.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                [$label, $tone] = audit_describe_action((string)$r['action']);
            ?>
                <tr>
                    <td class="text-nowrap"><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
                    <td><?= e($r['actor']) ?></td>
                    <?php if ($isSuper): ?><td><?= e($r['chama_name'] ?? 'platform') ?></td><?php endif; ?>
                    <td><span class="badge bg-<?= $tone ?>"><?= e($label) ?></span><div class="text-muted small"><code><?= e($r['action']) ?></code></div></td>
                    <td>
                        <?= e($r['description']) ?>
                        <?php if (!empty($r['meta'])): ?>
                            <details class="text-muted small"><summary>details</summary><pre class="mb-0 small bg-light p-2 rounded mt-1"><?= e(json_encode(json_decode($r['meta'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= e($r['ip_address'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="text-muted small">Page <?= $page ?> of <?= $pages ?></span>
        <div>
            <?php $qs = fn($p) => url('audit/index.php') . '?' . http_build_query(array_merge($_GET, ['page' => $p])); ?>
            <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e($qs($page - 1)) ?>">Prev</a>
            <a class="btn btn-sm btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= e($qs($page + 1)) ?>">Next</a>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
