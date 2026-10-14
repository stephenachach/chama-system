<?php
/**
 * JSON status poller for a pending subscription payment.
 * Used by the "waiting for confirmation" panel on the billing pages.
 * In live mode it also asks Daraja directly, so a lost callback still resolves.
 */

require_once __DIR__ . '/../config/auth.php';
require_login();

header('Content-Type: application/json');

$paymentId = (int)($_GET['payment_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM subscription_payments WHERE id = ? LIMIT 1');
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    echo json_encode(['status' => 'missing', 'message' => 'Payment request not found.']);
    exit;
}

// Tenancy: a chama user may only poll their own group's payments.
if (!is_superadmin() && (int)$payment['chama_id'] !== (int)current_chama_id()) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden', 'message' => 'Not your payment.']);
    exit;
}

// Still pending in live mode? Ask Daraja for the real result.
if ($payment['status'] === 'pending' && !mpesa_is_simulation() && !empty($payment['checkout_request_id'])) {
    $query = mpesa_query_stk((string)$payment['checkout_request_id']);
    if (!empty($query['paid'])) {
        complete_pending_payment(
            (int)$payment['id'],
            (string)($query['receipt'] ?? ''),
            (string)$payment['mpesa_phone'],
            (float)$payment['amount']
        );
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
    } elseif (isset($query['raw']['ResultCode']) && (int)$query['raw']['ResultCode'] !== 0) {
        mark_payment_failed((int)$payment['id'], (string)($query['raw']['ResultDesc'] ?? 'Daraja rejected the request'));
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
    }
}

$expiry = null;
if ($payment['status'] === 'paid') {
    $stmt = $pdo->prepare('SELECT plan_expires_at FROM chamas WHERE id = ?');
    $stmt->execute([(int)$payment['chama_id']]);
    $expiry = $stmt->fetchColumn() ?: null;
}

echo json_encode([
    'status' => $payment['status'],
    'receipt' => $payment['mpesa_receipt'],
    'amount' => (float)$payment['amount'],
    'expires_at' => $expiry,
    'message' => $payment['notes'] ?: '',
]);
