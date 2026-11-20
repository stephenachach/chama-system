<?php
/**
 * JSON status poller for pending M-Pesa payments.
 *
 *   ?payment_id=N  -> subscription_payments (platform fee)
 *   ?pay=N         -> mpesa_payments (member dues / loan repayment)
 *
 * Used by the "waiting for confirmation" panels. In live mode it also asks
 * Daraja directly, so a lost callback still resolves.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/mpesa_payments.php';
require_login();

header('Content-Type: application/json');

$user = current_user();

// --- Member-facing payments -------------------------------------------------
$payId = (int)($_GET['pay'] ?? 0);
if ($payId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM mpesa_payments WHERE id = ? LIMIT 1');
    $stmt->execute([$payId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        echo json_encode(['status' => 'missing', 'message' => 'Payment request not found.']);
        exit;
    }
    if (!mpesa_pay_visible_to($payment, $user)) {
        http_response_code(403);
        echo json_encode(['status' => 'forbidden', 'message' => 'Not your payment.']);
        exit;
    }

    // Pending + live mode? Ask Daraja for the real result.
    if ($payment['status'] === 'pending' && !mpesa_is_simulation() && !empty($payment['checkout_request_id'])) {
        $query = mpesa_query_stk((string)$payment['checkout_request_id']);
        if (!empty($query['paid'])) {
            mpesa_pay_complete((int)$payment['id'], (string)($query['receipt'] ?? ''), (string)$payment['phone'], (float)$payment['amount']);
        } elseif (isset($query['raw']['ResultCode']) && (int)$query['raw']['ResultCode'] !== 0) {
            mpesa_pay_fail((int)$payment['id'], (string)($query['raw']['ResultDesc'] ?? 'Daraja rejected the request'));
        }
        $stmt->execute([$payId]);
        $payment = $stmt->fetch();
    }

    $extra = [];
    if ($payment['status'] === 'paid') {
        if ($payment['purpose'] === 'contribution') {
            $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM contributions WHERE member_id = ?');
            $stmt->execute([(int)$payment['member_id']]);
            $extra['member_total'] = (float)$stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare('SELECT balance, status FROM loans WHERE id = ?');
            $stmt->execute([(int)$payment['loan_id']]);
            $loan = $stmt->fetch();
            $extra['loan_balance'] = (float)($loan['balance'] ?? 0);
            $extra['loan_status'] = $loan['status'] ?? '';
        }
    }

    echo json_encode(array_merge([
        'status' => $payment['status'],
        'receipt' => $payment['mpesa_receipt'],
        'amount' => (float)$payment['amount'],
        'purpose' => $payment['purpose'],
        'message' => $payment['notes'] ?: '',
    ], $extra));
    exit;
}

// --- Platform subscription payments ----------------------------------------
$paymentId = (int)($_GET['payment_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM subscription_payments WHERE id = ? LIMIT 1');
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    echo json_encode(['status' => 'missing', 'message' => 'Payment request not found.']);
    exit;
}

if (!is_superadmin() && (int)$payment['chama_id'] !== (int)current_chama_id()) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden', 'message' => 'Not your payment.']);
    exit;
}

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
