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
<div class="container py-5">
    <div class="row align-items-center gx-5">

        <div class="col-12 col-md-6 order-md-1">
            <p class="hero-kicker">Chama Management System</p>

            <h1 class="hero-title">
                <span class="hero-soft">Welcome to</span>
                <span class="hero-accent">Welfare</span>
                <span class="hero-soft">and</span>
                <span class="hero-accent">Chama</span>
                <span class="hero-main">Management System</span>
            </h1>

            <p class="hero-subtitle">Monitor your contribution.</p>
            <div class="d-flex flex-column flex-sm-row gap-3 mb-5 animate-slide-left" style="animation-delay:0.5s;">
                <a href="<?= url('login.php') ?>" class="btn btn-light btn-lg shadow-sm">Login</a>
                <a href="<?= url('register.php') ?>" class="btn btn-outline-light btn-lg">Register</a>
            </div>
        </div>

        <div class="col-12 col-md-6 order-md-2">
            <div class="hero-photo card animate-slide-right" style="animation-delay:0.35s;">
                <div class="hero-photo-card">
                    <div class="hero-photo-label">Welfare Community</div>
                    <div class="hero-photo-content">
                        <div class="hero-photo-icon">🌿</div>
                        <div>
                            <h5 class="mb-1">Track Your Chama Progress</h5>
                            <p class="text-muted mb-0">A clear snapshot of loan, welfare, and contribution flow.</p>
                        </div>
                    </div>
                </div>

                <div class="hero-stat-card">
                    <div class="hero-stat-header">
                        <div>
                            <div class="hero-photo-label">System overview</div>
                            <h6 class="mb-1">Progress bars show action areas</h6>
                        </div>
                        <span class="badge bg-primary bg-opacity-15 text-primary">Live update</span>
                    </div>

                    <div class="hero-stat-row">
                        <div class="hero-stat-title">Loan approvals</div>
                        <div class="hero-stat-bar"><span style="width: 82%;"></span></div>
                    </div>

                    <div class="hero-stat-row">
                        <div class="hero-stat-title">Welfare payouts</div>
                        <div class="hero-stat-bar"><span style="width: 64%;"></span></div>
                    </div>

                    <div class="hero-stat-row">
                        <div class="hero-stat-title">Member contributions</div>
                        <div class="hero-stat-bar"><span style="width: 91%;"></span></div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php $tiers = plan_tiers(); ?>
<div class="container pb-4">

    <div class="text-center mb-4 animate-slide-left">
        <h2 class="fw-bold text-white">Everything a chama needs to run like a business</h2>
        <p class="text-white-50 mb-0">Built with Kenyan savings groups, for Kenyan savings groups.</p>
    </div>

    <div class="row g-3 mb-5">
        <?php
        $features = [
            ['fa-sack-dollar', 'Dues that collect themselves', 'Members tap "Pay my dues", get the Safaricom PIN prompt, and the contribution records itself with the receipt number.'],
            ['fa-hand-holding-dollar', 'Loans that manage themselves', 'Balances fall with every repayment, overpayments are impossible, and a settled loan closes itself.'],
            ['fa-comment-sms', 'SMS that chases money for you', 'Automatic monthly reminders to whoever has not paid, plus an instant "received, asante" receipt on every payment.'],
            ['fa-file-pdf', 'Statements anyone can trust', 'One click gives a member a branded PDF: contributions, repayments, loan position and totals - ready for the AGM.'],
            ['fa-shield-halved', 'An audit trail behind everything', 'Every shilling move is logged with who did it and when. Treasurers sleep; disputes end quickly.'],
            ['fa-users-gear', 'Multi-group from day one', 'Each chama is its own sealed tenant: its members, its money, its reports. Add a second group without mixing a coin.'],
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

    <div class="text-center mb-4" id="pricing">
        <h2 class="fw-bold text-white">Simple pricing, per chama</h2>
        <p class="text-white-50">One setup fee when we onboard you, then one monthly subscription.
            Every plan starts with <strong class="text-white"><?= PILOT_DAYS ?> days free</strong> - no card, no contract.</p>
    </div>

    <div class="row g-3 justify-content-center mb-4">
        <?php foreach ($tiers as $key => $t): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow h-100 border-0 <?= $t['highlight'] ? 'border-primary border-2' : '' ?>" style="<?= $t['highlight'] ? 'transform:scale(1.02);' : '' ?>">
                <?php if ($t['highlight']): ?><div class="card-header bg-primary text-white text-center fw-semibold small">Most chamas pick this</div><?php endif; ?>
                <div class="card-body d-flex flex-column">
                    <h4 class="fw-bold mb-1"><?= e($t['label']) ?></h4>
                    <div class="text-muted small mb-3"><?= e($t['blurb']) ?></div>
                    <div class="mb-1"><span class="display-6 fw-bold">KSh <?= number_format($t['monthly'], 0) ?></span><span class="text-muted">/month</span></div>
                    <div class="text-muted small mb-3">+ KSh <?= number_format($t['setup'], 0) ?> one-off setup &amp; training</div>
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

    <div class="row g-3 mb-5">
        <?php
        $steps = [
            ['1', 'Fill one form', 'Two minutes: your chama name, your phone, a password. Your group exists immediately with everything unlocked.'],
            ['2', 'Add members & dues', 'Type them in or share your group registration link on WhatsApp. Set the monthly dues once.'],
            ['3', 'Collect on autopilot', 'Members self-pay by M-Pesa, reminders chase the rest by SMS, statements print themselves at the AGM.'],
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

    <div class="card shadow-lg border-0 mb-4" style="background:linear-gradient(120deg, rgba(45,64,226,.95), rgba(101,99,221,.95));">
        <div class="card-body p-4 p-md-5 text-center text-white">
            <h3 class="fw-bold mb-2">Ready to stop chasing contributions in WhatsApp threads?</h3>
            <p class="mb-4 opacity-75">Your first <?= PILOT_DAYS ?> days are free. After that, staying costs less than one missed contribution per month.</p>
            <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                <a href="<?= url('start.php') ?>" class="btn btn-light btn-lg fw-semibold">Start my free pilot</a>
                <a href="<?= url('login.php') ?>" class="btn btn-outline-light btn-lg">I already have an account</a>
            </div>
        </div>
    </div>

    <p class="text-center text-white-50 small pb-3 mb-0">
        &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> - built for chamas, table-banking groups and welfare societies in Kenya.
        <span class="mx-1 opacity-50">|</span> <a href="<?= url('login.php') ?>" class="link-light">Login</a>
        <span class="mx-1 opacity-50">|</span> <a href="<?= url('register.php') ?>" class="link-light">Join your chama</a>
    </p>
</div>
</body>
</html>
