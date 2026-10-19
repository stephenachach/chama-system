<?php
/**
 * CSV export (managers; also available while a group is read-only, because a
 * group's records must never be locked away from the group).
 *
 *   reports/export.php?type=contributions | members | loans | welfare
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_role(['admin', 'treasurer']);

$type = (string)($_GET['type'] ?? 'contributions');
$chamaId = current_chama_id();

$queries = [
    'contributions' => [
        'file' => 'contributions',
        'sql' => "SELECT c.contribution_date AS Date, m.member_no AS 'Member No', m.full_name AS Member, c.type AS Type, c.amount AS 'Amount (KES)', c.reference AS Reference, c.notes AS Notes
                    FROM contributions c JOIN members m ON m.id = c.member_id
                   WHERE c.chama_id = ? ORDER BY c.contribution_date DESC, c.id DESC",
    ],
    'members' => [
        'file' => 'members',
        'sql' => "SELECT member_no AS 'Member No', full_name AS Name, phone AS Phone, email AS Email, join_date AS 'Join date', status AS Status FROM members WHERE chama_id = ? ORDER BY full_name",
    ],
    'loans' => [
        'file' => 'loans',
        'sql' => "SELECT l.application_date AS Applied, m.full_name AS Member, l.amount AS 'Principal (KES)', l.interest_rate AS 'Rate %', l.total_payable AS 'Payable (KES)', l.balance AS 'Balance (KES)', l.status AS Status, l.purpose AS Purpose
                    FROM loans l JOIN members m ON m.id = l.member_id
                   WHERE l.chama_id = ? ORDER BY l.application_date DESC",
    ],
    'welfare' => [
        'file' => 'welfare',
        'sql' => "SELECT w.request_date AS Requested, m.full_name AS Member, w.amount AS 'Amount (KES)', w.status AS Status, w.reason AS Reason FROM welfare_payouts w JOIN members m ON m.id = w.member_id WHERE w.chama_id = ? ORDER BY w.request_date DESC",
    ],
];

if (!isset($queries[$type])) {
    http_response_code(404);
    exit('Unknown export.');
}

$stmt = $pdo->prepare($queries[$type]['sql']);
$stmt->execute([$chamaId]);
$rows = $stmt->fetchAll();

audit_log('report.export', 'report', null, 'Exported ' . $type . ' as CSV (' . count($rows) . ' rows)');

$name = $queries[$type]['file'] . '-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
if ($rows) {
    fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $row) {
        fputcsv($out, array_map(fn($v) => $v === null ? '' : $v, array_values($row)));
    }
} else {
    fputcsv($out, ['No records']);
}
fclose($out);
exit;
