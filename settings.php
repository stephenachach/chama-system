<?php
/**
 * Platform settings (superadmin). Currently: public support contacts shown
 * on the landing page. Values are deliberately NOT pre-filled with fake data.
 */
$page_title = 'Platform Settings';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_role(['superadmin']);

if (!function_exists('mb_strlen_str')) {
    function mb_strlen_str(string $s, int $len): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
    }
}

$keys = ['support_email', 'support_phone', 'support_whatsapp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($keys as $k) {
        $v = trim((string)($_POST[$k] ?? ''));
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$k, mb_strlen_str($v, 250)]);
    }
    audit_log('settings.update', 'settings', null, 'Platform support contacts updated');
    flash_success('Settings saved. The public site now shows these contacts.');
    header('Location: ' . url('settings.php'));
    exit;
}

$values = [];
foreach ($pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $r) {
    $values[$r['setting_key']] = $r['setting_value'];
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="page-head d-flex flex-column flex-md-row justify-content-between align-items-start gap-2">
    <div>
        <h1 class="h3 fw-bold mb-1"><i class="fa-solid fa-sliders me-2"></i>Platform settings</h1>
        <div class="text-muted mb-0">Support contacts shown publicly on the landing page. Leave a field empty to hide it.</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header">Support contacts (public)</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Support email</label>
                        <input type="email" class="form-control" name="support_email" value="<?= e($values['support_email'] ?? '') ?>" placeholder="support@yourdomain.co.ke">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Support phone</label>
                        <input type="text" class="form-control" name="support_phone" value="<?= e($values['support_phone'] ?? '') ?>" placeholder="+254 7XX XXX XXX">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">WhatsApp (same or different number)</label>
                        <input type="text" class="form-control" name="support_whatsapp" value="<?= e($values['support_whatsapp'] ?? '') ?>" placeholder="+254 7XX XXX XXX">
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i>Save settings</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header">How this is used</div>
            <div class="card-body small text-muted">
                <p>The landing page shows a "Talk to us" section with whichever of these you fill in. Empty fields are hidden - nothing fake is ever shown.</p>
                <p class="mb-0">Pilot groups also get your contact details in their onboarding SMS, so keep the phone current.</p>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
