<?php
define('CSRF_EXEMPT', true);   // public form / machine endpoint: CSRF handled per-flow
/**
 * start.php - public pilot signup (Phase 4).
 *
 * A group leader fills one form and gets a working chama immediately:
 * the group, their admin account and a free PILOT_DAYS-day window. When the
 * window ends the ordinary subscription machinery takes over (expiry ->
 * suspend -> renew by M-Pesa), so pilots convert to paying customers with
 * zero manual work from you.
 *
 * The one-off setup fee is recorded as due; you mark it paid from
 * Billing > Pilot pipeline once they settle it.
 */
$page_title = 'Start your chama';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/plans.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/sms.php';

if (is_logged_in()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$tiers = plan_tiers();
$error = '';
$done = null;      // set to the created chama row on success
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot: humans never see or fill this field.
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $error = 'Sorry, that submission looked like spam.';
    }

    $chama_name  = trim((string)($_POST['chama_name'] ?? ''));
    $phone       = trim((string)($_POST['phone'] ?? ''));
    $town        = trim((string)($_POST['town'] ?? ''));
    $tier_key    = (string)($_POST['tier'] ?? 'standard');
    $admin_name  = trim((string)($_POST['admin_name'] ?? ''));
    $admin_email = strtolower(trim((string)($_POST['admin_email'] ?? '')));
    $password    = (string)($_POST['password'] ?? '');
    $confirm     = (string)($_POST['confirm_password'] ?? '');
    $member_count = (int)($_POST['member_count'] ?? 0);

    $tier = plan_tier($tier_key);

    if ($chama_name === '' || $admin_name === '' || $admin_email === '' || $phone === '') {
        $error = 'Chama name, your name, email and phone are all required.';
    } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address - it becomes your login.';
    } elseif (strlen($password) < 6) {
        $error = 'Choose a password of at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } elseif (!$tier) {
        $error = 'Please choose a plan.';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$admin_email]);
        if ((int)$stmt->fetchColumn() > 0) {
            $error = 'That email already has an account. Log in instead, or use a different email.';
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            $slug = unique_chama_slug($pdo, $chama_name);
            $expires = pilot_ends_at();

            $stmt = $pdo->prepare(
                "INSERT INTO chamas
                    (name, slug, phone, billing_phone, email, plan, monthly_fee, monthly_dues,
                     plan_expires_at, status, started_at, notes,
                     is_pilot, tier, setup_fee, setup_fee_paid)
                 VALUES (?, ?, ?, ?, ?, 'pro', ?, 0, ?, 'active', CURDATE(), ?, 1, ?, ?, 0)"
            );
            $stmt->execute([
                $chama_name,
                $slug,
                $phone,
                $phone,
                '',
                (float)$tier['monthly'],
                $expires,
                trim(('Pilot signup via website. ' . ($town !== '' ? 'Town: ' . $town . '. ' : '') . ($member_count > 0 ? 'Est. members: ' . $member_count . '.' : ''))),
                $tier_key,
                (float)$tier['setup'],
            ]);
            $chamaId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO members (chama_id, member_no, full_name, phone, join_date, status)
                 VALUES (?, 'M-001', ?, ?, CURDATE(), 'active')"
            );
            $stmt->execute([$chamaId, $admin_name, $phone]);
            $memberId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO users (chama_id, member_id, name, email, password, role, account_status)
                 VALUES (?, ?, ?, ?, ?, 'admin', 'approved')"
            );
            $stmt->execute([$chamaId, $memberId, $admin_name, $admin_email, password_hash($password, PASSWORD_DEFAULT)]);

            $pdo->commit();

            $stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ?');
            $stmt->execute([$chamaId]);
            $done = $stmt->fetch();

            audit_log('pilot.signup', 'chama', $chamaId, sprintf('New pilot via website: %s (%s tier, setup KSh %s due). Admin %s <%s>', $chama_name, $tier['label'], number_format((float)$tier['setup'], 2), $admin_name, $admin_email), ['tier' => $tier_key, 'member_estimate' => $member_count]);

            sms_send([
                'chama_id'  => $chamaId,
                'member_id' => $memberId,
                'phone'     => $phone,
                'purpose'   => 'notice',
                'message'   => sprintf('Karibu %s! %s is live on a free %d-day pilot. Log in with %s and add your members. Asante!', strtok($admin_name, ' ') ?: $admin_name, $chama_name, PILOT_DAYS, $admin_email),
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not create your chama right now. Please try again or contact us. (' . $e->getMessage() . ')';
        }
    }
}

// ---- success screen ---------------------------------------------------------
if ($done) {
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>You're in! - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="hero-blue">
<div class="container py-5" style="max-width: 760px;">
    <div class="card shadow-lg border-0">
        <div class="card-body p-4 p-md-5">
            <div class="display-6 fw-bold mb-2">🎉 <?= e($done['name']) ?> is live</div>
            <p class="text-muted mb-4">Your free <strong><?= PILOT_DAYS ?>-day pilot</strong> runs to
                <strong><?= date('d M Y', strtotime((string)$done['plan_expires_at'])) ?></strong>. Everything is unlocked from minute one.</p>
            <ol class="mb-4">
                <li class="mb-2"><a href="<?= url('login.php') ?>"><strong>Log in now</strong></a> with <code><?= e($old['admin_email'] ?? '') ?></code> and your password.</li>
                <li class="mb-2">Add your members (<strong>Members &gt; New Member</strong>) or share the registration link on your group WhatsApp.</li>
                <li class="mb-2">Set the monthly dues in <strong>Chamas &gt; Edit</strong> so members can self-pay by M-Pesa.</li>
                <li>When the pilot ends, the group renews by M-Pesa from <strong>Subscription</strong> - the same button your members already know.</li>
            </ol>
            <div class="alert alert-warning small mb-4">
                One-off setup fee of <strong>KSh <?= number_format((float)$done['setup_fee'], 2) ?></strong> is due on onboarding
                (data entry, training call, WhatsApp support group). We will confirm payment details with you by phone.
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-primary btn-lg" href="<?= url('login.php') ?>">Go to login</a>
                <a class="btn btn-outline-secondary btn-lg" href="<?= url('index.php') ?>">Back to home</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="hero-blue">
<div class="container py-5" style="max-width: 860px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <a href="<?= url('index.php') ?>" class="btn btn-outline-light">&larr; Home &amp; pricing</a>
        <a href="<?= url('login.php') ?>" class="btn btn-light">Login</a>
    </div>

    <div class="card shadow-lg border-0">
        <div class="card-body p-4 p-md-5">
            <h1 class="h3 fw-bold mb-1">Start your chama - free for <?= PILOT_DAYS ?> days</h1>
            <p class="text-muted mb-4">One form, and your group is running today: contributions, loans, M-Pesa
                self-pay, SMS reminders and PDF statements. No card, no contract.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="row g-3" autocomplete="on">
            <?= csrf_field() ?>
                <input type="text" name="website" value="" style="position:absolute; left:-9999px;" tabindex="-1" autocomplete="off" aria-hidden="true">

                <div class="col-md-8">
                    <label class="form-label fw-semibold">Chama name *</label>
                    <input class="form-control form-control-lg" name="chama_name" required value="<?= e($old['chama_name'] ?? '') ?>" placeholder="e.g. Umoja Women Savings Group">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Town / county</label>
                    <input class="form-control form-control-lg" name="town" value="<?= e($old['town'] ?? '') ?>" placeholder="Nairobi">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Your phone (M-Pesa) *</label>
                    <input class="form-control form-control-lg" name="phone" required value="<?= e($old['phone'] ?? '') ?>" placeholder="07XX XXX XXX">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Approximate members</label>
                    <input type="number" min="0" max="5000" class="form-control form-control-lg" name="member_count" value="<?= e($old['member_count'] ?? '') ?>" placeholder="25">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Plan after the pilot</label>
                    <select class="form-select form-select-lg" name="tier">
                        <?php foreach ($tiers as $key => $t): ?>
                        <option value="<?= e($key) ?>" <?= ($old['tier'] ?? $_GET['tier'] ?? 'growth') === $key ? 'selected' : '' ?>>
                            <?= e($t['label']) ?> - KSh <?= number_format($t['monthly'], 0) ?>/month (<?= e($t['members']) ?>)<?= ($t['setup'] ?? 0) > 0 ? ', plus KSh ' . number_format($t['setup'], 0) . ' setup' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">The first <?= PILOT_DAYS ?> days are free on every plan. Cancel any time by simply not renewing.</div>
                </div>

                <hr class="my-1">

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Your full name *</label>
                    <input class="form-control form-control-lg" name="admin_name" required value="<?= e($old['admin_name'] ?? '') ?>" placeholder="You - the group secretary/treasurer">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Your email (becomes login) *</label>
                    <input type="email" class="form-control form-control-lg" name="admin_email" required value="<?= e($old['admin_email'] ?? '') ?>" placeholder="you@example.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Password *</label>
                    <input type="password" class="form-control form-control-lg" name="password" required minlength="6">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Confirm password *</label>
                    <input type="password" class="form-control form-control-lg" name="confirm_password" required minlength="6">
                </div>

                <div class="col-12 d-grid d-md-flex justify-content-md-end gap-2">
                    <a href="<?= url('index.php') ?>" class="btn btn-outline-secondary btn-lg">Cancel</a>
                    <button class="btn btn-primary btn-lg">Create my chama - it's free today</button>
                </div>
            </form>
        </div>
    </div>
    <p class="text-center text-white-50 small mt-3 mb-0">Already a member of a chama on this system?
        <a href="<?= url('login.php') ?>" class="link-light">Log in</a> or
        <a href="<?= url('register.php') ?>" class="link-light">join your group</a>. · <a href="<?= url('privacy.php') ?>" class="link-light">Privacy &amp; security</a></p>
</div>
</body>
</html>
