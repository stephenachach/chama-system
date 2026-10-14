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

// ---------------------------------------------------------------------------
// Multi-tenancy helpers
// ---------------------------------------------------------------------------

// Platform operator: manages chamas, not inside any single chama.
function is_superadmin(): bool
{
    $user = current_user();
    return (bool)($user && $user['role'] === 'superadmin');
}

// The id of the chama the logged-in user belongs to (null for superadmin).
function current_chama_id(): ?int
{
    $user = current_user();
    if (!$user || $user['role'] === 'superadmin') {
        return null;
    }
    return isset($user['chama_id']) ? (int)$user['chama_id'] : null;
}

// Full chamas row for the logged-in user's chama (null for superadmin).
function current_chama(): ?array
{
    global $pdo;
    $chamaId = current_chama_id();
    if ($chamaId === null) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ?');
    $stmt->execute([$chamaId]);
    return $stmt->fetch() ?: null;
}

// Block the platform operator from chama-scoped pages.
function require_chama(): void
{
    require_login();
    if (is_superadmin()) {
        header('Location: ' . url('chamas/index.php'));
        exit;
    }
}

// Role guard. Chama-scoped pages list ['admin','treasurer',('member')] and
// automatically redirect the superadmin to the platform area instead.
function require_role(array $roles): void
{
    require_login();
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        if ($user && $user['role'] === 'superadmin') {
            header('Location: ' . url('chamas/index.php'));
        } else {
            $_SESSION['flash_error'] = 'You do not have permission to access that page.';
            header('Location: ' . url('dashboard.php'));
        }
        exit;
    }
}

// Fetch a record only if it belongs to the current chama (IDOR protection).
function fetch_in_chama(string $table, int $id, ?array $columns = null): ?array
{
    global $pdo;
    $allowed = ['users', 'members', 'contributions', 'loans', 'welfare_payouts'];
    if (!in_array($table, $allowed, true) || $id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? AND chama_id = ? LIMIT 1");
    $stmt->execute([$id, current_chama_id()]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------------

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
