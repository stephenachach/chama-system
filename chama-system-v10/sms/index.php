<?php
/**
 * SMS composer: reminders and broadcasts to your members.
 *
 * In simulation mode (the default, no credentials) messages are logged and
 * shown in SMS > Log so you can rehearse the whole flow for free. Add
 * config/sms-secrets.php with Africa's Talking credentials to go live.
 */
$page_title = 'SMS';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/sms.php';
require_role(['admin', 'treasurer']);

$chamaId = current_chama_id();
$chama = current_chama();
$cfg = sms_config();
$monthLabel = date('F Y');
$dues = (float)($chama['monthly_dues'] ?? 0);

// Audiences ------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT id, member_no, full_name, phone FROM members WHERE chama_id = ? AND status = 'active' ORDER BY full_name");
$stmt->execute([$chamaId]);
$active = $stmt->fetchAll();

$monthStart = date('Y-m-01');
$stmt = $pdo->prepare('SELECT DISTINCT member_id FROM contributions WHERE chama_id = ? AND contribution_date >= ? AND type <> \'welfare\'');
$stmt->execute([$chamaId, $monthStart]);
$paidIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
$unpaid = array_values(array_filter($active, fn($m) => !in_array((int)$m['id'], $paidIds, true)));

$sent = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $audience = (string)($_POST['audience'] ?? 'unpaid');
    $singleId = (int)($_POST['member_id'] ?? 0);
    $template = trim((string)($_POST['message'] ?? ''));

    if ($template === '') {
        flash_error('Write the message first.');
    } else {
        if ($audience === 'all') {
            $recipients = $active;
        } elseif ($audience === 'one') {
            $recipients = array_values(array_filter($active, fn($m) => (int)$m['id'] === $singleId));
            if (!$recipients) {
                flash_error('Choose the member to message.');
            }
        } else {
            $recipients = $unpaid;
        }
        if (!$recipients) {
            flash_error('Nobody matches that audience - every active member has paid this month.');
        } else {
            $okCount = 0;
            $failCount = 0;
            foreach ($recipients as $m) {
                $msg = str_replace(
                    ['{name}', '{first}', '{chama}', '{dues}', '{month}'],
                    [$m['full_name'], strtok($m['full_name'], ' ') ?: $m['full_name'], $chama['name'] ?? 'the chama', number_format($dues, 2), $monthLabel],
                    $template
                );
                $res = sms_send([
                    'chama_id'  => $chamaId,
                    'member_id' => (int)$m['id'],
                    'phone'     => (string)$m['phone'],
                    'purpose'   => $audience === 'unpaid' ? 'reminder' : 'broadcast',
                    'message'   => $msg,
                ]);
                $res['ok'] ? $okCount++ : $failCount++;
            }
            audit_log('sms.send', 'sms', null, sprintf('Sent %s SMS to %d member(s) (%s audience); %d failed', $cfg['provider'] === 'simulation' ? 'simulated' : 'real', count($recipients), $audience, $failCount), ['audience' => $audience, 'ok' => $okCount, 'failed' => $failCount]);
            $verb = $cfg['provider'] === 'simulation' ? 'simulated (demo mode - nothing was really sent)' : 'sent';
            flash_success(sprintf('%d message(s) %s%s. See SMS > Log for the details.', $okCount, $verb, $failCount ? ", $failCount failed" : ''));
            header('Location: ' . url('sms/index.php'));
            exit;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
$defaultMsg = "Dear {first}, a friendly reminder that your {month} dues of KSh {dues} for {chama} are not yet recorded. Tap Pay Dues in the app or see your treasurer. Asante!";
?>
<div class="card hero-card shadow-sm mb-4">
    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1"><i class="fa-solid fa-comment-sms me-2"></i>SMS Messages</h2>
            <div class="text-muted">Dues reminders and group announcements, straight to members' phones.</div>
        </div>
        <a href="<?= url('sms/logs.php') ?>" class="btn btn-outline-primary btn-lg">Message Log</a>
    </div>
</div>

<?php if ($cfg['provider'] === 'simulation'): ?>
<div class="alert alert-info d-flex align-items-start gap-2">
    <i class="fa-solid fa-flask mt-1"></i>
    <div>
        <strong>Demo mode.</strong> Messages are recorded in the log but not really sent - perfect for
        testing. To send real SMS, copy <code>config/sms-secrets.example.php</code> to
        <code>config/sms-secrets.php</code> and add your (free) Africa's Talking credentials.
    </div>
</div>
<?php else: ?>
<div class="alert alert-success"><i class="fa-solid fa-tower-broadcast me-2"></i>Live SMS via Africa's Talking<?= $cfg['sender_id'] !== '' ? ' (sender ID: ' . e($cfg['sender_id']) . ')' : '' ?>.
    Group credit: <strong><?= (int)($chama['sms_balance'] ?? 0) ?> unit(s)</strong> - SMS is prepaid (1 unit ~ one 160-character message); top-ups are arranged with the platform operator.</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header">Compose</div>
            <div class="card-body">
                <form method="post">
            <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Send to</label>
                        <select name="audience" id="audience" class="form-select">
                            <option value="unpaid">Members who have NOT paid <?= e($monthLabel) ?> dues (<?= count($unpaid) ?>)</option>
                            <option value="all">All active members (<?= count($active) ?>)</option>
                            <option value="one">One member...</option>
                        </select>
                    </div>
                    <div class="mb-3 d-none" id="oneWrap">
                        <label class="form-label">Member</label>
                        <select name="member_id" class="form-select">
                            <?php foreach ($active as $m): ?>
                            <option value="<?= (int)$m['id'] ?>"><?= e($m['full_name'] . ' (' . $m['member_no'] . ') - ' . $m['phone']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Message</label>
                        <textarea name="message" id="msg" rows="5" class="form-control" maxlength="480"><?= e($defaultMsg) ?></textarea>
                        <div class="form-text"><span id="cnt">0</span>/480 characters. Placeholders: <code>{name}</code> <code>{first}</code> <code>{chama}</code> <code>{dues}</code> <code>{month}</code></div>
                    </div>
                    <button class="btn btn-primary btn-lg"><i class="fa-solid fa-paper-plane me-2"></i>Send now</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header">Who is unpaid this month?</div>
            <div class="card-body">
                <?php if (!$unpaid): ?>
                    <div class="text-success"><i class="fa-solid fa-circle-check me-2"></i>Everyone active has paid <?= e($monthLabel) ?>. Beautiful.</div>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                    <?php foreach (array_slice($unpaid, 0, 12) as $m): ?>
                        <li class="d-flex justify-content-between py-1 border-bottom"><span><?= e($m['full_name']) ?></span><span class="text-muted"><?= e($m['phone'] ?: 'no phone') ?></span></li>
                    <?php endforeach; ?>
                    </ul>
                    <?php if (count($unpaid) > 12): ?><div class="text-muted small mt-2">+<?= count($unpaid) - 12 ?> more</div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header">Automatic monthly reminders</div>
            <div class="card-body">
                <?php if ((int)($chama['sms_enabled'] ?? 0) === 1): ?>
                    <div class="mb-1"><span class="badge bg-success">On</span> Unpaid members get one reminder on day <?= (int)($chama['sms_reminder_day'] ?? 5) ?> of each month.</div>
                    <div class="text-muted small">Run daily with: <code>php cron/reminders.php</code> (or open it with your token). Change the day in Chamas &gt; Edit.</div>
                <?php else: ?>
                    <div class="text-muted">Off. Turn it on in <a href="<?= url('chamas/edit.php') ?>?id=<?= (int)$chamaId ?>">Chamas &gt; Edit &gt; SMS settings</a> and schedule <code>cron/reminders.php</code>.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('audience').addEventListener('change', function () {
    document.getElementById('oneWrap').classList.toggle('d-none', this.value !== 'one');
});
function cnt() { document.getElementById('cnt').textContent = document.getElementById('msg').value.length; }
document.getElementById('msg').addEventListener('input', cnt); cnt();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
