<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . url('index.php'));
        exit;
    }
}

function require_role(array $roles): void
{
    require_login();
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        $_SESSION['flash_error'] = 'You do not have permission to access that page.';
        header('Location: ' . url('dashboard.php'));
        exit;
    }
}

function flash_success(string $message): void
{
    $_SESSION['flash_success'] = $message;
}

function flash_error(string $message): void
{
    $_SESSION['flash_error'] = $message;
}

function show_flash(): void
{
    if (!empty($_SESSION['flash_success'])) {
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">' . e($_SESSION['flash_success']) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        unset($_SESSION['flash_success']);
    }
    if (!empty($_SESSION['flash_error'])) {
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . e($_SESSION['flash_error']) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        unset($_SESSION['flash_error']);
    }
}
