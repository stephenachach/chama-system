<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/plans.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['user'])) {
    header('Location: ' . url('dashboard.php'));
    exit;
}
$page_title = 'Welcome';

// Support contacts (optional, superadmin-set). The landing page must render
// even if the database is unreachable, so this is best-effort.
$support = [];
try {
    $__c = require __DIR__ . '/config/db-credentials.php';
    $__pdo = new PDO(
        'mysql:host=' . $__c['host'] . ';dbname=' . $__c['dbname'] . ';charset=utf8mb4',
        (string)$__c['username'],
        (string)$__c['password'],
        [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    foreach ($__pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (trim((string)$r['setting_value']) !== '') {
            $support[$r['setting_key']] = trim((string)$r['setting_value']);
        }
    }
} catch (Throwable $ignored) {
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="hero-blue">

<!-- ===================== HERO ===================== -->
<div class="container py-5">
    <div class="row align-items-center gx-5">
        <div class="col-12 col-md-6 order-md-1">
            <p class="hero-kicker">Chama &amp; Welfare Group Software - Kenya</p>
            <h1 class="hero-title">
                <span class="hero-soft">Run your chama like a</span>
                <span class="hero-main">business, not a WhatsApp thread</span>
            </h1>
            <p class="hero-subtitle">Contributions, loans, welfare and statements in one place.
                Member money goes straight to <strong>your group's own M-Pesa Paybill/Till</strong> - we never hold it
                and never take a percentage.</p>
            <div class="d-flex flex-column flex-sm-row gap-2 mb-3">
                <a href="<?= url('start.php') ?>" class="btn btn-light btn-lg fw-semibold shadow-sm"><i class="fa-solid fa-rocket me-1"></i>Start a group</a>
                <a href="<?= url('register.php') ?>" class="btn btn-outline-light btn-lg"><i class="fa-solid fa-user-plus me-1"></i>Join an existing group</a>
            </div>
            <p class="mb-0 small"><a href="<?= url('login.php') ?>" class="link-light">Already have an account? Log in &rarr;</a></p>
        </div>

        <div class="col-12 col-md-6 order-md-2 mt-4 mt-md-0">
            <!-- Honest product preview: what the committee dashboard actually looks like.
                 Clearly labelled as illustrative; no fake "live" numbers. -->
            <div class="card shadow-lg border-0" aria-label="Illustrative product preview">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-primary bg-opacity-10 text-primary">Illustrative preview - sample figures</span>
                        <span class="small text-muted">Committee dashboard</span>
                    </div>
                    <div class="border rounded-3 p-3 mb-2" style="background:linear-gradient(120deg,#123c7b,#2f6fe4); color:#fff;">
                        <div class="fw-bold">Good afternoon, Grace</div>
                        <div class="small opacity-75">Nairobi Traders Chama &middot; Monday 5 October</div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><div class="border rounded-3 p-2 text-center"><div class="small text-muted">This month</div><div class="fw-bold">KSh 12,500</div><div class="progress mt-1" style="height:6px;"><div class="progress-bar bg-success" style="width:72%"></div></div><div class="small text-muted mt-1">18 of 22 paid</div></div></div>
                        <div class="col-6">
                            <div class="row g-2">
                                <div class="col-6"><div class="border rounded-3 p-2 text-center"><div class="fw-bold text-danger">2</div><div class="small text-muted">Welfare</div></div></div>
                                <div class="col-6"><div class="border rounded-3 p-2 text-center"><div class="fw-bold text-warning">1</div><div class="small text-muted">Loans</div></div></div>
                                <div class="col-6"><div class="border rounded-3 p-2 text-center"><div class="fw-bold text-info">1</div><div class="small text-muted">Sign-ups</div></div></div>
                                <div class="col-6"><div class="border rounded-3 p-2 text-center"><div class="fw-bold text-primary">3</div><div class="small text-muted">Payments</div></div></div>
                            </div>
                        </div>
                    </div>
                    <div class="border rounded-3 p-2 small text-muted"><i class="fa-solid fa-mobile-screen-button text-success me-1"></i>Peter paid KSh 500 by M-Pesa &middot; receipt QGH7... &middot; recorded automatically</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $tiers = plan_tiers(); ?>
<div class="container pb-4">

    <!-- ===================== TWO PATHS ===================== -->
    <div class="text-center mb-4">
        <h2 class="fw-bold text-white">Which one are you?</h2>
        <p class="text-white-50 mb-0">Two different doors - pick the right one and you're set in minutes.</p>
    </div>
    <div class="row g-3 mb-5">
        <div class="col-md-6">
            <div class="card shadow h-100 border-0">
                <div class="card-body p-4">
                    <div class="fs-2 text-primary mb-2"><i class="fa-solid fa-people-group"></i></div>
                    <h3 class="h4 fw-bold">I want to START a group</h3>
                    <p class="text-muted small">You are a chairperson, treasurer or organiser. You'll create the group, set monthly dues, add members and run contributions, loans and welfare.</p>
                    <ul class="small mb-3">
                        <li><?= PILOT_DAYS ?> days free, then from <strong>KSh <?= number_format(min(array_map(fn($t) => (float)$t['monthly'], $tiers)) ?: 150, 0) ?>/month</strong> for the whole group</li>
                        <li>Members pay dues into <strong>your own</strong> Paybill/Till</li>
                        <li>No percentage of your savings or loans - ever</li>
                    </ul>
                    <a href="<?= url('start.php') ?>" class="btn btn-primary btn-lg">Start a group - free pilot</a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow h-100 border-0">
                <div class="card-body p-4">
                    <div class="fs-2 text-success mb-2"><i class="fa-solid fa-user-plus"></i></div>
                    <h3 class="h4 fw-bold">I want to JOIN my group</h3>
                    <p class="text-muted small">Your chama, welfare society or table-banking group already uses this system. You'll track your own dues, loans, welfare and statements.</p>
                    <ul class="small mb-3">
                        <li>Use the <strong>registration link your group shared</strong> (WhatsApp/SMS), or pick your group from the list</li>
                        <li>Your committee approves your account before you can log in</li>
                        <li>Free for members - your group pays the subscription, never you</li>
                    </ul>
                    <a href="<?= url('register.php') ?>" class="btn btn-success btn-lg">Join an existing group</a>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== FEATURES ===================== -->
    <div class="text-center mb-4">
        <h2 class="fw-bold text-white">Everything a chama needs to run like a business</h2>
        <p class="text-white-50 mb-0">Built with Kenyan savings groups, for Kenyan savings groups.</p>
    </div>
    <div class="row g-3 mb-5">
        <?php
        $features = [
            ['fa-sack-dollar', 'Dues that collect themselves', 'Members tap "Pay my dues", get the Safaricom PIN prompt, and the contribution records itself with the receipt number - into the group\'s own verified Paybill/Till.'],
            ['fa-vault', 'Your money stays yours', 'Group savings never pass through our account and we take no percentage of contributions, loans or welfare. The only money you pay us is the subscription.'],
            ['fa-hand-holding-dollar', 'Loans that manage themselves', 'Balances fall with every repayment, overpayments are impossible, and a settled loan closes itself. Large welfare payouts can require two officers\' approval.'],
            ['fa-comment-sms', 'Honest SMS reminders', 'Automatic reminders to whoever has not paid, from prepaid SMS credit you control - you always know the cost per message and what is left.'],
            ['fa-file-pdf', 'Statements anyone can trust', 'One click gives a member a branded PDF: contributions, repayments, loan position and totals - ready for the AGM.'],
            ['fa-shield-halved', 'An audit trail behind everything', 'Every shilling move is logged with who did it and when. Treasurers sleep; disputes end quickly.'],
        ];
        foreach ($features as $i => [$icon, $title, $body]): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100 border-0">
                <div class="card-body">
                    <div class="fs-3 text-primary mb-2"><i class="fa-solid <?= $icon ?>"></i></div>
                    <h5 class="fw-bold"><?= $title ?></h5>
                    <p class="text-muted mb-0 small"><?= $body ?></p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ===================== HOW IT WORKS ===================== -->
    <div class="text-center mb-4" id="how-it-works">
        <h2 class="fw-bold text-white">How it works</h2>
        <p class="text-white-50 mb-0">For the committee - and for members.</p>
    </div>
    <div class="row g-3 mb-3">
        <?php
        $steps = [
            ['1', 'Fill one form', 'Two minutes: your group name, your phone, a password. Your group exists immediately with everything unlocked for ' . PILOT_DAYS . ' days.'],
            ['2', 'Add members & dues', 'Type members in, or share your group registration link on WhatsApp and approve sign-ups. Set the monthly dues once.'],
            ['3', 'Collect & report', 'Members self-pay by M-Pesa, reminders chase the rest by SMS, and statements/reports are one click at the AGM.'],
        ];
        foreach ($steps as [$n, $title, $body]): ?>
        <div class="col-md-4">
            <div class="card shadow-sm h-100 border-0 text-center">
                <div class="card-body">
                    <div class="display-6 fw-bold text-primary bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width:64px;height:64px;"><?= $n ?></div>
                    <h5 class="fw-bold"><?= $title ?></h5>
                    <p class="text-muted small mb-0"><?= $body ?></p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="card shadow-sm border-0 mb-5">
        <div class="card-body py-3 small text-muted">
            <strong class="text-dark"><i class="fa-solid fa-user me-1"></i>For members:</strong>
            register through your group's link &rarr; committee approves you &rarr; log in to see your dues and due date, pay with M-Pesa, request welfare or a loan, and download your statement any time. <strong class="text-dark">Members never pay us anything.</strong>
        </div>
    </div>

    <!-- ===================== PRICING ===================== -->
    <div class="text-center mb-4" id="pricing">
        <h2 class="fw-bold text-white">Simple pricing, per group</h2>
        <p class="text-white-50">No setup fee - one monthly subscription per group, paid by the group (never by members).
            Every plan starts with <strong class="text-white"><?= PILOT_DAYS ?> days free</strong> - no card, no contract.
            Pay annually and get <strong class="text-white">12 months for the price of 10</strong>.</p>
    </div>
    <div class="row g-3 justify-content-center mb-5">
        <?php foreach ($tiers as $key => $t): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow h-100 border-0 <?= $t['highlight'] ? 'border-primary border-2' : '' ?>" style="<?= $t['highlight'] ? 'transform:scale(1.02);' : '' ?>">
                <?php if ($t['highlight']): ?><div class="card-header bg-primary text-white text-center fw-semibold small">Most chamas pick this</div><?php endif; ?>
                <div class="card-body d-flex flex-column">
                    <h4 class="fw-bold mb-1"><?= e($t['label']) ?></h4>
                    <div class="text-muted small mb-3"><?= e($t['blurb']) ?></div>
                    <div class="mb-1"><span class="display-6 fw-bold">KSh <?= number_format($t['monthly'], 0) ?></span><span class="text-muted">/month</span></div>
                    <div class="text-muted small mb-3"><?= $t['setup'] > 0 ? '+ KSh ' . number_format($t['setup'], 0) . ' one-off setup &amp; training' : 'Assisted setup &amp; data import optional - quoted separately' ?></div>
                    <div class="badge bg-light text-dark border mb-3 align-self-start"><?= e($t['members']) ?></div>
                    <ul class="list-unstyled small mb-4">
                        <?php foreach ($t['features'] as $f): ?>
                        <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i><?= e($f) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?= url('start.php?tier=' . $key) ?>" class="btn btn-<?= $t['highlight'] ? 'primary' : 'outline-primary' ?> mt-auto btn-lg">Start free pilot</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ===================== FAQ ===================== -->
    <div class="text-center mb-4" id="faq">
        <h2 class="fw-bold text-white">Questions groups ask us</h2>
        <p class="text-white-50 mb-0">Straight answers - no small print games.</p>
    </div>
    <div class="row justify-content-center mb-5">
        <div class="col-lg-8">
            <div class="accordion shadow" id="faqAccordion">
                <?php
                $faqs = [
                    ['Where does our money go?', 'Member contributions and loan repayments are paid by M-Pesa STK directly into <strong>your group\'s own Paybill or Till number</strong>, which we verify before switching it on. The platform never pools or holds group funds, and we take no percentage of your savings, loans or welfare - the only payment to us is your subscription.'],
                    ['What happens if our subscription expires?', 'Your group switches to <strong>read-only</strong>: everyone can still log in, view all records, download statements and export CSV files. Nothing is deleted or held hostage. Editing switches back on when you renew.'],
                    ['Is SMS unlimited?', 'No - and we don\'t pretend otherwise. SMS runs on <strong>prepaid credit per group</strong> (1 SMS = 1 unit; long messages cost more). You can see the balance at any time, and reminders stop if credit runs out rather than silently failing. Provider charges per message are disclosed when you top up.'],
                    ['How do our members join?', 'Share your group registration link (WhatsApp works great) or let them pick your group on the Join page. You approve each sign-up before they can log in - so strangers can\'t wander in.'],
                    ['Who can see welfare cases?', 'Only your group\'s committee (admin/treasurer). Members see only their own request and its status. For extra protection you can set a payout threshold: any welfare payout at or above it needs a <strong>second approval by a different officer</strong> before it can be marked paid.'],
                    ['Can we run more than one group?', 'Yes. Each group is completely separate - its own members, money records, reports and Paybill route. Contact us and we\'ll set up the second group under its own subscription.'],
                    ['Can we change plans later?', 'Any time. Plans and member caps are managed per group; upgrades apply immediately and we work out the fair difference. Message us using the contact details below (or those shared during your onboarding).'],
                    ['Is our data safe?', 'Passwords are hashed, every group\'s data is strictly separated, all destructive actions need confirmation, and logins are throttled against guessing. Details are on our <a href="' . url('privacy.php') . '" class="text-decoration-none">Privacy &amp; security</a> page. Data-protection obligations are handled with a qualified Kenyan privacy professional.'],
                ];
                foreach ($faqs as $i => [$q, $a]): ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button <?= $i === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>">
                            <span class="fw-semibold"><?= $q ?></span>
                        </button>
                    </h2>
                    <div id="faq<?= $i ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>" data-bs-parent="#faqAccordion">
                        <div class="accordion-body small text-muted"><?= $a ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ===================== SUPPORT ===================== -->
    <?php if ($support): ?>
    <div class="card shadow border-0 mb-4" id="support">
        <div class="card-body p-4 text-center">
            <h3 class="h4 fw-bold mb-3"><i class="fa-solid fa-headset text-primary me-2"></i>Talk to us</h3>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <?php if (!empty($support['support_email'])): ?>
                <a class="btn btn-outline-primary" href="mailto:<?= e($support['support_email']) ?>"><i class="fa-solid fa-envelope me-1"></i><?= e($support['support_email']) ?></a>
                <?php endif; ?>
                <?php if (!empty($support['support_phone'])): ?>
                <a class="btn btn-outline-success" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $support['support_phone'])) ?>"><i class="fa-solid fa-phone me-1"></i><?= e($support['support_phone']) ?></a>
                <?php endif; ?>
                <?php if (!empty($support['support_whatsapp'])): ?>
                <a class="btn btn-outline-success" href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', str_replace('+', '', $support['support_whatsapp']))) ?>"><i class="fa-brands fa-whatsapp me-1"></i>WhatsApp <?= e($support['support_whatsapp']) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===================== FINAL CTA ===================== -->
    <div class="card shadow-lg border-0 mb-4" style="background:linear-gradient(120deg, rgba(45,64,226,.95), rgba(101,99,221,.95));">
        <div class="card-body p-4 p-md-5 text-center text-white">
            <h3 class="fw-bold mb-2">Ready to stop chasing contributions in WhatsApp threads?</h3>
            <p class="mb-4 opacity-75">Your first <?= PILOT_DAYS ?> days are free. After that, staying costs less than one missed contribution per month.</p>
            <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                <a href="<?= url('start.php') ?>" class="btn btn-light btn-lg fw-semibold">Start my group</a>
                <a href="<?= url('register.php') ?>" class="btn btn-outline-light btn-lg">Join my group</a>
            </div>
        </div>
    </div>

    <p class="text-center text-white-50 small pb-3 mb-0">
        &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> - built for chamas, table-banking groups and welfare societies in Kenya.
        <span class="mx-1 opacity-50">|</span> <a href="<?= url('login.php') ?>" class="link-light">Login</a>
        <span class="mx-1 opacity-50">|</span> <a href="<?= url('start.php') ?>" class="link-light">Start a group</a>
        <span class="mx-1 opacity-50">|</span> <a href="<?= url('register.php') ?>" class="link-light">Join a group</a>
        <span class="mx-1 opacity-50">|</span> <a href="#faq" class="link-light">FAQ</a>
        <span class="mx-1 opacity-50">|</span> <a href="<?= url('privacy.php') ?>" class="link-light">Privacy &amp; security</a>
    </p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
