<?php
$page_title = 'M-Pesa Logs';
require_once __DIR__ . '/../config/auth.php';
require_role(['superadmin']);
require_once __DIR__ . '/../includes/header.php';

// Every Daraja request, callback and system event, newest first.
$logs = $pdo->query('SELECT * FROM mpesa_logs ORDER BY id DESC LIMIT 200')->fetchAll();
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">M-Pesa Logs</h2>
            <div class="text-muted">Raw request/response trail. Use this when a payment does not confirm.</div>
        </div>
        <a href="<?= url('billing/index.php') ?>" class="btn btn-outline-primary btn-lg">Back to Billing</a>
    </div>
</div>

<div class="alert alert-secondary small">
    Mode: <strong><?= mpesa_is_simulation() ? 'SIMULATION (no Daraja credentials)' : 'LIVE (' . e(ucfirst(MPESA_ENV)) . ')' ?></strong>
    &middot; Callback URL: <code><?= e(mpesa_callback_url()) ?></code>
    <?php if (!mpesa_is_simulation()): ?>
        &middot; Short code: <code><?= e(MPESA_SHORTCODE) ?></code>
    <?php endif; ?>
    <?php if (!function_exists('curl_init')): ?>
        <div class="text-danger mt-1"><strong>cURL is disabled</strong> in this PHP install - live M-Pesa calls will fail.
            Edit <code>php.ini</code>, uncomment <code>extension=curl</code>, then restart Apache.</div>
    <?php endif; ?>
</div>

<div class="card shadow-sm">
    <div class="card-body table-responsive p-0">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th style="width:150px">When</th><th style="width:60px">Dir</th><th style="width:180px">Endpoint</th><th>Payload</th></tr></thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="4" class="text-muted p-3">No M-Pesa activity yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="small"><?= e(date('d M Y H:i:s', strtotime((string)$l['created_at']))) ?></td>
                    <td><span class="badge bg-<?= $l['direction'] === 'in' ? 'info' : 'secondary' ?>"><?= e(strtoupper($l['direction'])) ?></span></td>
                    <td class="small"><code><?= e($l['endpoint']) ?></code><?= !empty($l['receipt_no']) ? '<br><small class="text-muted">' . e($l['receipt_no']) . '</small>' : '' ?></td>
                    <td><pre class="small mb-0" style="max-height:120px;overflow:auto;white-space:pre-wrap;"><?= e((string)$l['payload']) ?></pre></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
