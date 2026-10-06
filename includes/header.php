<?php
require_once __DIR__ . '/../config/auth.php';
require_login();
$page_title = $page_title ?? APP_NAME;
$user = current_user();
$isManager = in_array($user['role'], ['admin', 'treasurer'], true);
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
$showLoansNav = $isManager || $user['role'] === 'member';
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
        <a class="navbar-brand" href="<?= url('dashboard.php') ?>">Chama System</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="topNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="<?= url('dashboard.php') ?>">Dashboard</a></li>
                <?php if ($isManager): ?>
                    <li class="nav-item"><a class="nav-link <?= $currentPage === 'members' || $currentPage === 'members/index.php' ? 'active' : '' ?>" href="<?= url('members/index.php') ?>">Members</a></li>
                    <li class="nav-item"><a class="nav-link <?= $currentPage === 'contributions' || $currentPage === 'contributions/index.php' ? 'active' : '' ?>" href="<?= url('contributions/index.php') ?>">Contributions</a></li>
                <?php endif; ?>
                <?php if ($showLoansNav): ?>
                    <li class="nav-item"><a class="nav-link <?= $activeLoans ? 'active' : '' ?>" href="<?= url('loans/index.php') ?>">Loans</a></li>
                <?php endif; ?>
                <?php if ($isManager): ?>
                    <li class="nav-item"><a class="nav-link <?= $currentPage === 'welfare' || $currentPage === 'welfare/index.php' ? 'active' : '' ?>" href="<?= url('welfare/index.php') ?>">Welfare</a></li>
                    <li class="nav-item"><a class="nav-link <?= $activeReports ? 'active' : '' ?>" href="<?= url('reports/index.php') ?>">Reports</a></li>
                    <li class="nav-item"><a class="nav-link <?= $activeApprovals ? 'active' : '' ?>" href="<?= url('users/approvals.php') ?>">Approvals</a></li>
                <?php endif; ?>
                <?php if ($user['role'] === 'admin'): ?>
                    <li class="nav-item"><a class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" href="<?= url('users/index.php') ?>">Users</a></li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-3">
                <div class="text-muted small text-nowrap">Logged in as <?= e($user['name']) ?> (<?= e(ucfirst($user['role'])) ?>)</div>
                <a class="btn btn-outline-primary btn-sm" href="<?= url('logout.php') ?>">Logout</a>
            </div>
        </div>
    </div>
</nav>
<main class="container-fluid py-4 px-4">
    <?php show_flash(); ?>
