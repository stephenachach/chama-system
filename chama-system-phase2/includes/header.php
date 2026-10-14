<?php
require_once __DIR__ . '/../config/auth.php';
require_login();
$page_title = $page_title ?? APP_NAME;
$user = current_user();
$isSuper = is_superadmin();
$isManager = in_array($user['role'], ['admin', 'treasurer'], true);
$chama = $isSuper ? null : current_chama();
$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($uri, PHP_URL_PATH);
$currentPage = basename($path);
$currentFolder = trim(dirname($path), '/');
$activeDashboard = $currentPage === 'dashboard.php';
$activeMembers = $currentFolder === 'members';
$activeContributions = $currentFolder === 'contributions';
$activeLoans = $currentFolder === 'loans';
$activeWelfare = $currentFolder === 'welfare';
$activeReports = $currentPage === 'reports.php' || $currentFolder === 'reports';
$activeApprovals = $currentPage === 'approvals.php';
$activeUsers = $currentFolder === 'users' && $currentPage === 'index.php';
$activeChamas = $currentFolder === 'chamas';
$activeBilling = $currentFolder === 'billing' || $currentFolder === 'mpesa' || $currentFolder === 'cron';
$activeAccount = $currentPage === 'password.php';
$activeSubscription = $currentFolder === 'account' && $currentPage === 'subscription.php';
$showLoansNav = $isManager || $user['role'] === 'member';
$brandName = $isSuper ? APP_NAME : (e($chama['name'] ?? 'Chama System'));

// Subscription awareness (Phase 2).
$restricted = subscription_restricted_mode();
$subStatus = (!$isSuper && $chama) ? subscription_status($chama) : null;
$showSubAlert = !$isSuper && $subStatus && in_array($subStatus['state'], ['expiring', 'grace', 'expired'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light sticky-top shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand" href="<?= url('dashboard.php') ?>"><?= $brandName ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="topNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if ($restricted): ?>
                    <li class="nav-item"><a class="nav-link <?= $activeSubscription ? 'active' : '' ?>" href="<?= url('account/subscription.php') ?>">Subscription</a></li>
                <?php else: ?>
                <li class="nav-item"><a class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <?php if ($isSuper): ?>
                    <li class="nav-item"><a class="nav-link <?= $activeChamas ? 'active' : '' ?>" href="<?= url('chamas/index.php') ?>">Chamas</a></li>
                    <li class="nav-item"><a class="nav-link <?= $activeBilling ? 'active' : '' ?>" href="<?= url('billing/index.php') ?>">Billing</a></li>
                <?php else: ?>
                    <?php if ($isManager): ?>
                        <li class="nav-item"><a class="nav-link <?= $activeMembers ? 'active' : '' ?>" href="<?= url('members/index.php') ?>">Members</a></li>
                        <li class="nav-item"><a class="nav-link <?= $activeContributions ? 'active' : '' ?>" href="<?= url('contributions/index.php') ?>">Contributions</a></li>
                    <?php endif; ?>
                    <?php if ($showLoansNav): ?>
                        <li class="nav-item"><a class="nav-link <?= $activeLoans ? 'active' : '' ?>" href="<?= url('loans/index.php') ?>">Loans</a></li>
                    <?php endif; ?>
                    <?php if ($isManager): ?>
                        <li class="nav-item"><a class="nav-link <?= $activeWelfare ? 'active' : '' ?>" href="<?= url('welfare/index.php') ?>">Welfare</a></li>
                        <li class="nav-item"><a class="nav-link <?= $activeReports ? 'active' : '' ?>" href="<?= url('reports/index.php') ?>">Reports</a></li>
                        <li class="nav-item"><a class="nav-link <?= $activeApprovals ? 'active' : '' ?>" href="<?= url('users/approvals.php') ?>">Approvals</a></li>
                    <?php endif; ?>
                    <?php if ($user['role'] === 'admin'): ?>
                        <li class="nav-item"><a class="nav-link <?= $activeUsers ? 'active' : '' ?>" href="<?= url('users/index.php') ?>">Users</a></li>
                    <?php endif; ?>
                    <?php if ($isManager): ?>
                        <li class="nav-item"><a class="nav-link <?= $activeSubscription ? 'active' : '' ?>" href="<?= url('account/subscription.php') ?>">Subscription</a></li>
                    <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
                <li class="nav-item"><a class="nav-link <?= $activeAccount ? 'active' : '' ?>" href="<?= url('account/password.php') ?>">My Account</a></li>
            </ul>
            <div class="d-flex align-items-center gap-3">
                <div class="text-muted small text-nowrap">
                    Logged in as <?= e($user['name']) ?> (<?= $isSuper ? 'Platform' : e(ucfirst($user['role'])) ?>)
                    <?php if (!$isSuper && $chama && $chama['status'] !== 'active'): ?>
                        <span class="badge bg-danger">Group suspended</span>
                    <?php elseif (!$isSuper && $subStatus && $subStatus['expires_at'] && $isManager): ?>
                        <span class="badge bg-<?= e($subStatus['class']) ?>" title="Subscription expiry">
                            <?= e($subStatus['expires_at'] ? 'Plan: ' . date('d M Y', strtotime((string)$subStatus['expires_at'])) : '') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <a class="btn btn-outline-primary btn-sm" href="<?= url('logout.php') ?>">Logout</a>
            </div>
        </div>
    </div>
</nav>
<main class="container-fluid py-4 px-4">
    <?php show_flash(); ?>
    <?php if ($restricted): ?>
    <div class="alert alert-danger d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2" role="alert">
        <div>
            <strong>Subscription expired - access suspended.</strong>
            <?php if ($subStatus && $subStatus['expires_at']): ?>
                Your plan ended on <?= e(date('d M Y', strtotime((string)$subStatus['expires_at']))) ?>.
            <?php endif; ?>
            Pay <?= money($chama['monthly_fee'] ?? 0) ?> by M-Pesa to restore full access immediately.
        </div>
        <a class="btn btn-danger btn-sm text-nowrap" href="<?= url('account/subscription.php') ?>">Pay now</a>
    </div>
    <?php elseif ($showSubAlert && !$activeSubscription): ?>
    <div class="alert alert-<?= $subStatus['state'] === 'grace' || $subStatus['state'] === 'expired' ? 'danger' : 'warning' ?> d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2" role="alert">
        <div>
            <strong><?= e($subStatus['label']) ?></strong>
            <?php if ($subStatus['expires_at']): ?>
                (<?= e(date('d M Y', strtotime((string)$subStatus['expires_at']))) ?>).
            <?php endif; ?>
            Renew to keep <?= e($chama['name']) ?> running without interruption.
        </div>
        <a class="btn btn-sm btn-outline-dark text-nowrap" href="<?= url('account/subscription.php') ?>">Renew by M-Pesa</a>
    </div>
    <?php endif; ?>
    <?php
    // Warn the platform operator while the initial password is still in use.
    if ($isSuper) {
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();
        if ($hash && password_verify(SUPERADMIN_INITIAL_PASSWORD, (string)$hash)):
    ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <strong>Security notice:</strong> the platform account is still using its initial password.
        Change it now in <a href="<?= url('account/password.php') ?>">My Account</a> and update the
        <code>SUPERADMIN_INITIAL_PASSWORD</code> constant in <code>config/app.php</code>.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php
    }
    ?>
