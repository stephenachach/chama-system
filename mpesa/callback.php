<?php
/**
 * M-Pesa Daraja STK Push callback endpoint. PUBLIC - no login, no session.
 *
 * Safaricom POSTs the payment result here after the customer accepts or cancels
 * the prompt on their phone. The URL is protected by a shared token
 * (MPESA_CALLBACK_TOKEN) so strangers cannot forge "paid" results.
 *
 * Expected body:
 * { "Body": { "stkCallback": {
 *     "MerchantRequestID": "...", "CheckoutRequestID": "...",
 *     "ResultCode": 0, "ResultDesc": "The service request is processed successfully.",
 *     "CallbackMetadata": { "Item": [
 *        {"Name":"Amount","Value":500.00},
 *        {"Name":"MpesaReceiptNumber","Value":"SDF3..."},
 *        {"Name":"TransactionDate","Value":20261014120000},
 *        {"Name":"PhoneNumber","Value":2547...} ] } } } }
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/mpesa_payments.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true) ?: [];

// Always answer Safaricom quickly, even when something is wrong on our side.
$respond = function (int $code, string $desc) {
    echo json_encode(['ResultCode' => $code, 'ResultDesc' => $desc]);
    exit;
};

// --- 1. Token check ---------------------------------------------------------
$token = (string)($_GET['token'] ?? '');
if (!hash_equals((string)MPESA_CALLBACK_TOKEN, $token)) {
    mpesa_log('in', 'callback:rejected', ['reason' => 'invalid token', 'body' => $body]);
    http_response_code(403);
    $respond(1, 'Rejected');
}

mpesa_log('in', 'callback', $body, $body['Body']['stkCallback']['CheckoutRequestID'] ?? null);

// --- 2. Parse the callback --------------------------------------------------
$callback = $body['Body']['stkCallback'] ?? null;
if (!is_array($callback)) {
    $respond(1, 'Malformed callback body');
}

$checkoutId = (string)($callback['CheckoutRequestID'] ?? '');
$merchantId = (string)($callback['MerchantRequestID'] ?? '');
$resultCode = (int)($callback['ResultCode'] ?? -1);
$resultDesc = (string)($callback['ResultDesc'] ?? '');

$items = $callback['CallbackMetadata']['Item'] ?? [];
$receipt = null;
$amount = null;
$phone = null;
foreach (is_array($items) ? $items : [] as $item) {
    $name = $item['Name'] ?? '';
    $value = $item['Value'] ?? null;
    if ($name === 'MpesaReceiptNumber') {
        $receipt = (string)$value;
    } elseif ($name === 'Amount') {
        $amount = (float)$value;
    } elseif ($name === 'PhoneNumber') {
        $phone = (string)$value;
    }
}

if ($checkoutId === '') {
    $respond(1, 'Missing CheckoutRequestID');
}

// --- 3. Match it to a pending payment --------------------------------------
$stmt = $pdo->prepare("SELECT * FROM subscription_payments WHERE checkout_request_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$checkoutId]);
$payment = $stmt->fetch();

if (!$payment) {
    // --- Not a subscription: try member payments (dues / loan repayments) ---
    $stmt = $pdo->prepare("SELECT * FROM mpesa_payments WHERE checkout_request_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$checkoutId]);
    $memberPayment = $stmt->fetch();

    if ($memberPayment) {
        if ($merchantId !== '' && empty($memberPayment['merchant_request_id'])) {
            $pdo->prepare('UPDATE mpesa_payments SET merchant_request_id = ? WHERE id = ?')
                ->execute([$merchantId, (int)$memberPayment['id']]);
        }

        if ($resultCode === 0) {
            $ok = mpesa_pay_complete((int)$memberPayment['id'], (string)$receipt, $phone, $amount);
            mpesa_log('in', $ok ? 'callback:paid' : 'callback:error', [
                'mpesa_payment_id' => (int)$memberPayment['id'],
                'purpose' => $memberPayment['purpose'],
                'member_id' => (int)$memberPayment['member_id'],
                'loan_id' => $memberPayment['loan_id'],
                'receipt' => $receipt,
                'amount' => $amount,
            ], $checkoutId, $receipt);
            $respond($ok ? 0 : 1, $ok ? 'Accepted' : 'Processing failed');
        }

        mpesa_pay_fail((int)$memberPayment['id'], $resultDesc !== '' ? $resultDesc : ('Daraja result code ' . $resultCode));
        mpesa_log('in', 'callback:failed', [
            'mpesa_payment_id' => (int)$memberPayment['id'],
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
        ], $checkoutId);
        $respond(0, 'Accepted');
    }

    mpesa_log('in', 'callback:unmatched', ['checkout_request_id' => $checkoutId, 'result_code' => $resultCode], $checkoutId, $receipt);
    $respond(1, 'No matching payment request');
}

// Store the merchant id for reference.
if ($merchantId !== '' && empty($payment['merchant_request_id'])) {
    $pdo->prepare('UPDATE subscription_payments SET merchant_request_id = ? WHERE id = ?')
        ->execute([$merchantId, (int)$payment['id']]);
}

// --- 4. Apply the result ----------------------------------------------------
if ($resultCode === 0) {
    $ok = complete_pending_payment((int)$payment['id'], (string)$receipt, $phone, $amount);
    if ($ok) {
        $stmt = $pdo->prepare('SELECT name, plan_expires_at FROM chamas WHERE id = ?');
        $stmt->execute([(int)$payment['chama_id']]);
        $chama = $stmt->fetch();
        mpesa_log('in', 'callback:paid', [
            'payment_id' => (int)$payment['id'],
            'chama' => $chama['name'] ?? null,
            'new_expiry' => $chama['plan_expires_at'] ?? null,
            'receipt' => $receipt,
            'amount' => $amount,
        ], $checkoutId, $receipt);
        $respond(0, 'Accepted');
    }
    mpesa_log('in', 'callback:error', ['payment_id' => (int)$payment['id'], 'issue' => 'could not complete payment'], $checkoutId, $receipt);
    $respond(1, 'Processing failed');
}

// Non-zero = cancelled, timeout, insufficient funds, wrong PIN, etc.
mark_payment_failed((int)$payment['id'], $resultDesc !== '' ? $resultDesc : ('Daraja result code ' . $resultCode));
mpesa_log('in', 'callback:failed', [
    'payment_id' => (int)$payment['id'],
    'result_code' => $resultCode,
    'result_desc' => $resultDesc,
], $checkoutId);
$respond(0, 'Accepted');
