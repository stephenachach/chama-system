<?php
require_once __DIR__ . '/config/app.php';
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
</body>
</html>
