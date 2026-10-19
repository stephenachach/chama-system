<?php
/**
 * includes/audit.php - append-only audit trail.
 *
 * Every money, membership, access and configuration change goes through
 * audit_log() so a treasurer (or you, supporting a client) can answer
 * "who did this, and when?" without opening the database.
 *
 *   audit_log('contribution.add', 'contribution', $id, 'Recorded KSh 500 from Grace Wanjiku');
 */

require_once __DIR__ . '/../config/auth.php';

if (!function_exists('audit_log')) {
    /**
     * @param string      $action      dotted verb, e.g. 'loan.approve'
     * @param string      $entityType  'contribution' | 'loan' | 'member' | 'user' | 'chama' | 'payment' | 'sms' | ...
     * @param int|null    $entityId
     * @param string      $description human sentence shown in the viewer
     * @param array       $meta        extra detail stored as JSON (before/after values, amounts...)
     */
    function audit_log(string $action, string $entityType = '', $entityId = null, string $description = '', array $meta = []): void
    {
        global $pdo;
        if (!isset($pdo) || !$pdo instanceof PDO) {
            return;
        }
        try {
            $chamaId = function_exists('current_chama_id') ? current_chama_id() : null;
            $user = function_exists('current_user') ? current_user() : null;
            $userId = is_array($user) ? ($user['id'] ?? null) : null;
            $actor = is_array($user) ? ($user['name'] ?? '') : '';
            if ($actor === '' && is_array($user)) {
                $actor = (string)($user['email'] ?? '');
            }
            // Scripts (cron, callbacks) have no session: name them explicitly.
            if ($actor === '') {
                $actor = PHP_SAPI === 'cli' ? 'system (cron)' : 'system';
            }
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (chama_id, user_id, actor, action, entity_type, entity_id, description, meta, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $chamaId,
                $userId,
                str_cut($actor, 150),
                str_cut($action, 80),
                str_cut($entityType, 40),
                $entityId,
                str_cut($description, 480),
                $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : '',
            ]);
        } catch (Throwable $e) {
            // An audit failure must never break the operation being audited.
        }
    }
}

if (!function_exists('audit_describe_action')) {
    /** Badge label + tone for the audit viewer. */
    function audit_describe_action(string $action): array
    {
        $map = [
            'add'      => ['Added', 'success'],
            'create'   => ['Created', 'success'],
            'approve'  => ['Approved', 'success'],
            'pay'      => ['Payment', 'info'],
            'paid'     => ['Paid', 'info'],
            'update'   => ['Updated', 'warning'],
            'edit'     => ['Updated', 'warning'],
            'delete'   => ['Deleted', 'danger'],
            'reject'   => ['Rejected', 'danger'],
            'suspend'  => ['Suspended', 'danger'],
            'resume'   => ['Resumed', 'success'],
            'login'    => ['Login', 'secondary'],
            'failed'   => ['Failed', 'danger'],
        ];
        $verb = substr($action, strrpos($action, '.') === false ? 0 : strrpos($action, '.') + 1);
        return $map[$verb] ?? [ucfirst($verb), 'secondary'];
    }
}
