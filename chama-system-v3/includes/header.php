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
<nav class="navbar navbar-expand-lg navbar-light sticky-top shadow-sm app-nav">
    <?php /* ---- Tier 1: brand + who is logged in + Logout. Always visible at every width. ---- */ ?>
    <div class="container-fluid px-3 px-lg-4 nav-tier1">
        <div class="d-flex w-100 align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2" style="min-width: 0;">
                <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="collapse"
                        data-bs-target="#topNav" aria-controls="topNav" aria-expanded="false" aria-label="Menu">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <a class="navbar-brand text-truncate mb-0" href="<?= url('dashboard.php') ?>"><?= $brandName ?></a>
                <?php if ($restricted): ?>
                    <span class="badge bg-danger text-nowrap">Suspended</span>
                <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <div class="d-none d-md-flex flex-column align-items-end lh-sm" style="min-width: 0;">
                    <span class="text-muted small text-truncate" style="max-width: 210px;">
                        <i class="fa-regular fa-user me-1"></i><?= e($user['name']) ?><?php if (!$isSuper): ?> &middot; <?= e(ucfirst($user['role'])) ?><?php endif; ?>
                    </span>
                    <?php if (!$isSuper && $chama && $chama['status'] !== 'active'): ?>
                        <span class="badge bg-danger">Group suspended</span>
                    <?php elseif (!$isSuper && $subStatus && $subStatus['expires_at'] && $isManager): ?>
                        <span class="badge bg-<?= e($subStatus['class']) ?>" title="Subscription expiry">
                            Plan to <?= e(date('d M Y', strtotime((string)$subStatus['expires_at']))) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <a class="btn btn-outline-danger btn-sm text-nowrap flex-shrink-0" href="<?= url('logout.php') ?>">
                    <i class="fa-solid fa-right-from-bracket me-1"></i>Logout
                </a>
            </div>
        </div>
    </div>

    <?php /* ---- Tier 2: the menu. Wraps onto as many rows as it needs; hidden behind the hamburger on phones. ---- */ ?>
    <div class="collapse navbar-collapse nav-tier2" id="topNav">
        <div class="container-fluid px-3 px-lg-4">
            <ul class="navbar-nav flex-wrap w-100 gap-1 py-2 py-lg-1 mb-0">
                <?php if ($restricted): ?>
                    <li class="nav-item"><a class="nav-link <?= $activeSubscription ? 'active' : '' ?>" href="<?= url('account/subscription.php') ?>"><i class="fa-solid fa-credit-card me-1"></i>Subscription</a></li>
                <?php else: ?>
                <li class="nav-item"><a class="nav-link <?= $activeDashboard ? 'active' : '' ?>" href="<?= url('dashboard.php') ?>"><i class="fa-solid fa-gauge me-1"></i>Dashboard</a></li>
                <?php if ($isSuper): ?>
                    <li class="nav-item"><a class="nav-link <?= $activeChamas ? 'active' : '' ?>" href="<?= url('chamas/index.php') ?>"><i class="fa-solid fa-people-group me-1"></i>Chamas</a></li>
                    <li class="nav-item"><a class="nav-link <?= $activeBilling ? 'active' : '' ?>" href="<?= url('billing/index.php') ?>"><i class="fa-solid fa-money-bill-wave me-1"></i>Billing</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= url('mpesa/logs.php') ?>"><i class="fa-solid fa-list-check me-1"></i>M-Pesa Logs</a></li>
                <?php else: ?>
                    <?php if ($isManager): ?>
                        <li class="nav-item"><a class="nav-link <?= $activeMembers ? 'active' : '' ?>" href="<?= url('members/index.php') ?>"><i class="fa-solid fa-users me-1"></i>Members</a></li>
                        <li class="nav-item"><a class="nav-link <?= $activeContributions ? 'active' : '' ?>" href="<?= url('contributions/index.php') ?>"><i class="fa-solid fa-sack-dollar me-1"></i>Contributions</a></li>
                    <?php endif; ?>
                    <?php if ($showLoansNav): ?>
                        <li class="nav-item"><a class="nav-link <?= $activeLoans ? 'active' : '' ?>" href="<?= url('loans/index.php') ?>"><i class="fa-solid fa-hand-holding-dollar me-1"></i>Loans</a></li>
                    <?php endif; ?>
                    <?php if ($isManager): ?>
                        <li class="nav-item"><a class="nav-link <?= $activeWelfare ? 'active' : '' ?>" href="<?= url('welfare/index.php') ?>"><i class="fa-solid fa-heart me-1"></i>Welfare</a></li>
                        <li class="nav-item"><a class="nav-link <?= $activeReports ? 'active' : '' ?>" href="<?= url('reports/index.php') ?>"><i class="fa-solid fa-chart-pie me-1"></i>Reports</a></li>
                        <li class="nav-item"><a class="nav-link <?= $activeApprovals ? 'active' : '' ?>" href="<?= url('users/approvals.php') ?>"><i class="fa-solid fa-clipboard-check me-1"></i>Approvals</a></li>
                    <?php endif; ?>
                    <?php if ($user['role'] === 'admin'): ?>
                        <li class="nav-item"><a class="nav-link <?= $activeUsers ? 'active' : '' ?>" href="<?= url('users/index.php') ?>"><i class="fa-solid fa-user-shield me-1"></i>Users</a></li>
                    <?php endif; ?>
                    <?php if ($isManager): ?>
                        <li class="nav-item"><a class="nav-link <?= $activeSubscription ? 'active' : '' ?>" href="<?= url('account/subscription.php') ?>"><i class="fa-solid fa-credit-card me-1"></i>Subscription</a></li>
                    <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
                <li class="nav-item"><a class="nav-link <?= $activeAccount ? 'active' : '' ?>" href="<?= url('account/password.php') ?>"><i class="fa-solid fa-key me-1"></i>My Account</a></li>
                <li class="nav-item d-lg-none"><a class="nav-link text-danger" href="<?= url('logout.php') ?>"><i class="fa-solid fa-right-from-bracket me-1"></i>Logout</a></li>
            </ul>
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
