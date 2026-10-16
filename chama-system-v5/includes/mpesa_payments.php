<?php
/**
 * M-Pesa payments for member contributions and loan repayments (Phase 2b).
 *
 * A payment goes through three states:
 *   pending  - STK push sent, waiting for the customer's PIN / the callback
 *   paid     - callback confirmed; the real contribution or repayment record
 *              is created and the loan balance is reduced (in one transaction)
 *   failed   - cancelled, timed out or rejected
 *
 * The same completion code runs for the real Daraja callback and for the
 * "simulate customer paid" button, so demo mode exercises the production path.
 */

require_once __DIR__ . '/mpesa.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/audit.php';

/**
 * Start a contribution or loan-repayment payment.
 *
 * @param array $opts {chama_id, purpose, member_id, loan_id, amount, phone, initiated_by}
 * @return array{ok:bool,payment_id:int,checkout_id:string,simulated:bool,error:string}
 */
function mpesa_pay_initiate(array $opts): array
{
    global $pdo;

    $chamaId = (int)($opts['chama_id'] ?? 0);
    $purpose = (string)($opts['purpose'] ?? '');
    $memberId = (int)($opts['member_id'] ?? 0);
    $loanId = (int)($opts['loan_id'] ?? 0);
    $amount = round((float)($opts['amount'] ?? 0), 2);
    $phone = mpesa_normalize_phone((string)($opts['phone'] ?? ''));
    $initiatedBy = isset($opts['initiated_by']) ? (int)$opts['initiated_by'] : null;

    $fail = fn (string $msg) => ['ok' => false, 'payment_id' => 0, 'checkout_id' => '', 'simulated' => false, 'error' => $msg];

    if (!in_array($purpose, ['contribution', 'loan_repayment'], true)) {
        return $fail('Unknown payment type.');
    }
    if ($phone === '') {
        return $fail('Enter a valid Safaricom number to receive the M-Pesa prompt, e.g. 0712 345 678.');
    }
    if ($amount < 1) {
        return $fail('The amount must be at least KES 1.');
    }

    // --- Contribution -------------------------------------------------------
    if ($purpose === 'contribution') {
        $stmt = $pdo->prepare('SELECT id, member_no, full_name FROM members WHERE id = ? AND chama_id = ? LIMIT 1');
        $stmt->execute([$memberId, $chamaId]);
        $member = $stmt->fetch();
        if (!$member) {
            return $fail('That member does not belong to this group.');
        }
        $accountRef = 'D' . $memberId;
        $description = 'Chama dues';
        $label = 'contribution for ' . $member['full_name'];
    } else {
        // --- Loan repayment --------------------------------------------------
        $stmt = $pdo->prepare('SELECT l.*, m.full_name FROM loans l JOIN members m ON m.id = l.member_id WHERE l.id = ? AND l.chama_id = ? LIMIT 1');
        $stmt->execute([$loanId, $chamaId]);
        $loan = $stmt->fetch();
        if (!$loan) {
            return $fail('That loan does not belong to this group.');
        }
        if (!in_array($loan['status'], ['approved', 'paid'], true)) {
            return $fail('Only an approved loan can be repaid.');
        }
        $balance = (float)$loan['balance'];
        if ($balance <= 0) {
            return $fail('This loan is fully repaid already.');
        }
        if ($amount > $balance) {
            // Never collect more than is owed.
            $amount = $balance;
        }
        if ($memberId > 0 && (int)$loan['member_id'] !== $memberId) {
            return $fail('The loan belongs to a different member.');
        }
        $memberId = (int)$loan['member_id'];
        $accountRef = 'L' . $loanId;
        $description = 'Loan repay';
        $label = 'loan repayment for ' . $loan['full_name'];
    }

    $result = mpesa_stk_push($phone, $amount, $accountRef, $description);
    if (!$result['ok']) {
        return $fail($result['error']);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO mpesa_payments
            (chama_id, purpose, member_id, loan_id, amount, phone, status,
             checkout_request_id, merchant_request_id, initiated_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $chamaId,
        $purpose,
        $memberId,
        $purpose === 'loan_repayment' ? $loanId : null,
        $amount,
        $phone,
        (string)$result['checkout_request_id'],
        $result['merchant_request_id'] ?? null,
        $initiatedBy,
    ]);

    return [
        'ok' => true,
        'payment_id' => (int)$pdo->lastInsertId(),
        'checkout_id' => (string)$result['checkout_request_id'],
        'simulated' => !empty($result['simulated']),
        'error' => '',
        'label' => $label,
        'amount' => $amount,
    ];
}

/**
 * Apply a confirmed payment: create the contribution / repayment record and
 * adjust the loan balance, all in one transaction. Safe to call twice.
 */
function mpesa_pay_complete(int $paymentId, string $receipt, ?string $phone, ?float $amount): bool
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM mpesa_payments WHERE id = ? LIMIT 1');
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        return false;
    }
    if ($payment['status'] === 'paid') {
        return true;
    }

    // A receipt may only ever be used once, across the whole platform.
    if ($receipt !== '') {
        $stmt = $pdo->prepare('SELECT id FROM mpesa_payments WHERE mpesa_receipt = ? AND id <> ? LIMIT 1');
        $stmt->execute([$receipt, $paymentId]);
        if ($stmt->fetchColumn()) {
            mpesa_pay_fail($paymentId, 'Duplicate M-Pesa receipt ' . $receipt);
            return false;
        }
    }

    try {
        $pdo->beginTransaction();

        $recordId = null;

        if ($payment['purpose'] === 'contribution') {
            $stmt = $pdo->prepare(
                "INSERT INTO contributions
                    (chama_id, member_id, amount, contribution_date, type, reference, notes)
                 VALUES (?, ?, ?, CURDATE(), 'mpesa', ?, 'Paid by M-Pesa STK')"
            );
            $stmt->execute([
                (int)$payment['chama_id'],
                (int)$payment['member_id'],
                (float)$payment['amount'],
                $receipt,
            ]);
            $recordId = (int)$pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO loan_repayments (loan_id, amount, payment_date, reference, notes)
                 VALUES (?, ?, CURDATE(), ?, 'Paid by M-Pesa STK')"
            );
            $stmt->execute([(int)$payment['loan_id'], (float)$payment['amount'], $receipt]);
            $recordId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare('SELECT balance FROM loans WHERE id = ? FOR UPDATE');
            $stmt->execute([(int)$payment['loan_id']]);
            $balance = (float)$stmt->fetchColumn();
            $newBalance = max(0, $balance - (float)$payment['amount']);

            $pdo->prepare('UPDATE loans SET balance = ?, status = ? WHERE id = ?')
                ->execute([$newBalance, $newBalance <= 0 ? 'paid' : 'approved', (int)$payment['loan_id']]);
        }

        $pdo->prepare(
            "UPDATE mpesa_payments
                SET status = 'paid', paid_at = NOW(), mpesa_receipt = ?,
                    phone = COALESCE(?, phone), result_record_id = ?
              WHERE id = ?"
        )->execute([$receipt !== '' ? $receipt : null, $phone ?: null, $recordId, $paymentId]);

        $pdo->commit();

        // Phase 3: audit trail + confirmation SMS. Never allowed to fail the
        // payment itself, so everything below is best-effort.
        try {
            $stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$payment['chama_id']]);
            $chama = $stmt->fetch() ?: [];
            $member = [];
            if (!empty($payment['member_id'])) {
                $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$payment['member_id']]);
                $member = $stmt->fetch() ?: [];
            }
            $who = $member['full_name'] ?? ('member #' . $payment['member_id']);
            $phone = $phone !== null && $phone !== '' ? $phone : (string)($member['phone'] ?? '');

            if ($payment['purpose'] === 'contribution') {
                audit_log('payment.contribution_paid', 'payment', $paymentId, sprintf('%s paid KSh %s by M-Pesa (receipt %s); contribution recorded automatically', $who, number_format((float)$payment['amount'], 2), $receipt), ['receipt' => $receipt, 'record_id' => $recordId]);
                sms_payment_receipt($chama, $member, $phone, 'monthly dues', (float)$payment['amount'], $receipt);
            } else {
                $stmt = $pdo->prepare('SELECT balance, status FROM loans WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$payment['loan_id']]);
                $loan = $stmt->fetch() ?: [];
                $bal = (float)($loan['balance'] ?? 0);
                $extra = $bal > 0 ? sprintf('Balance KSh %s. ', number_format($bal, 2)) : 'Loan fully settled. ';
                audit_log('payment.loan_repaid', 'payment', $paymentId, sprintf('%s repaid KSh %s by M-Pesa (receipt %s); loan balance now KSh %s', $who, number_format((float)$payment['amount'], 2), $receipt, number_format($bal, 2)), ['receipt' => $receipt, 'loan_id' => $payment['loan_id'], 'record_id' => $recordId]);
                sms_payment_receipt($chama, $member, $phone, 'loan repayment', (float)$payment['amount'], $receipt, $extra);
            }
        } catch (Throwable $ignored) {
        }

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mpesa_log('in', 'pay:error', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
        return false;
    }
}

function mpesa_pay_fail(int $paymentId, string $reason): void
{
    global $pdo;
    $pdo->prepare("UPDATE mpesa_payments SET status = 'failed', notes = ? WHERE id = ? AND status = 'pending'")
        ->execute([substr($reason, 0, 250), $paymentId]);
}

/** Fetch a payment and check the caller is allowed to see it. */
function mpesa_pay_visible_to(array $payment, ?array $user): bool
{
    if (!$user) {
        return false;
    }
    if ($user['role'] === 'superadmin') {
        return true;
    }
    $chamaId = isset($user['chama_id']) ? (int)$user['chama_id'] : 0;
    if ((int)$payment['chama_id'] !== $chamaId) {
        return false;
    }
    if ($user['role'] === 'member') {
        return (int)$payment['member_id'] === (int)($user['member_id'] ?? 0);
    }
    return true;
}
