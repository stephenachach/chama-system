<?php
/**
 * Subscription billing: plan expiry, extensions, auto-suspension and payments.
 *
 * Every chama has:
 *   plan            free | pro
 *   monthly_fee     KES charged each month
 *   plan_expires_at NULL = never expires (free plan or trial)
 *   status          active | suspended
 *   suspension_reason '' | nonpayment | manual
 *
 * A chama whose plan_expires_at passes (plus a grace period) is suspended
 * automatically. Suspension for non-payment still lets the group's admin/treasurer
 * log in to a restricted area so they can pay and restore access themselves.
 */

require_once __DIR__ . '/mpesa.php';

// ---------------------------------------------------------------------------
// Status helpers
// ---------------------------------------------------------------------------

/**
 * @return array{state:string,label:string,class:string,days_left:?int,expires_at:?string}
 */
function subscription_status(array $chama): array
{
    $expiresAt = $chama['plan_expires_at'] ?? null;
    $fee = (float)($chama['monthly_fee'] ?? 0);
    $plan = $chama['plan'] ?? 'free';

    // No expiry date: free plan or an open-ended trial.
    if ($plan === 'free' && $fee <= 0 && empty($expiresAt)) {
        return ['state' => 'unlimited', 'label' => 'Free plan (no expiry)', 'class' => 'secondary', 'days_left' => null, 'expires_at' => null];
    }
    if (empty($expiresAt)) {
        return ['state' => 'unlimited', 'label' => 'No expiry set', 'class' => 'secondary', 'days_left' => null, 'expires_at' => null];
    }

    $today = new DateTimeImmutable(date('Y-m-d'));
    $expiry = new DateTimeImmutable(date('Y-m-d', strtotime((string)$expiresAt)));
    $daysLeft = (int)$today->diff($expiry)->format('%r%a');

    if ($daysLeft < 0) {
        $grace = SUBSCRIPTION_GRACE_DAYS;
        if ($daysLeft >= -$grace) {
            return ['state' => 'grace', 'label' => 'Expired - grace period (' . abs($daysLeft) . '/' . $grace . ' days)', 'class' => 'warning', 'days_left' => $daysLeft, 'expires_at' => $expiresAt];
        }
        return ['state' => 'expired', 'label' => 'Expired ' . abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' ago', 'class' => 'danger', 'days_left' => $daysLeft, 'expires_at' => $expiresAt];
    }
    if ($daysLeft <= SUBSCRIPTION_REMINDER_DAYS) {
        return ['state' => 'expiring', 'label' => 'Expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's'), 'class' => 'warning', 'days_left' => $daysLeft, 'expires_at' => $expiresAt];
    }
    return ['state' => 'active', 'label' => 'Active - ' . $daysLeft . ' days left', 'class' => 'success', 'days_left' => $daysLeft, 'expires_at' => $expiresAt];
}

function subscription_badge(array $chama): string
{
    $s = subscription_status($chama);
    $expiry = $s['expires_at'] ? ' - ' . date('d M Y', strtotime((string)$s['expires_at'])) : '';
    return '<span class="badge bg-' . e($s['class']) . '">' . e($s['label'] . $expiry) . '</span>';
}

/** The next amount due for a chama. */
function subscription_amount_due(array $chama): float
{
    return max(0, (float)($chama['monthly_fee'] ?? 0));
}

/**
 * The billing period a payment made today would cover.
 * Extends from the current expiry when it is still in the future (pre-paying),
 * otherwise starts today.
 */
function subscription_next_period(array $chama, int $months = 1): array
{
    $expiresAt = $chama['plan_expires_at'] ?? null;
    $today = date('Y-m-d');

    if (!empty($expiresAt) && strtotime((string)$expiresAt) > strtotime($today)) {
        $start = date('Y-m-d', strtotime((string)$expiresAt . ' +1 day'));
    } else {
        $start = $today;
    }
    $end = date('Y-m-d', strtotime($start . ' +' . max(1, $months) . ' month -1 day'));

    return ['start' => $start, 'end' => $end];
}

// ---------------------------------------------------------------------------
// Mutations
// ---------------------------------------------------------------------------

/** Extend (or start) a chama's plan and clear any non-payment suspension. */
function extend_subscription(int $chamaId, int $months = 1): ?string
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ? LIMIT 1');
    $stmt->execute([$chamaId]);
    $chama = $stmt->fetch();
    if (!$chama) {
        return null;
    }

    $period = subscription_next_period($chama, $months);

    $pdo->prepare(
        "UPDATE chamas
            SET plan_expires_at = ?,
                status = 'active',
                suspension_reason = ''
          WHERE id = ?"
    )->execute([$period['end'], $chamaId]);

    return $period['end'];
}

/** Move a chama back into service without a payment (goodwill / trial). */
function grant_trial(int $chamaId, int $days = 30): ?string
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT plan_expires_at FROM chamas WHERE id = ? LIMIT 1');
    $stmt->execute([$chamaId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $current = $row['plan_expires_at'] ?? null;
    $from = (!empty($current) && strtotime((string)$current) > time()) ? (string)$current : date('Y-m-d');
    $end = date('Y-m-d', strtotime($from . ' +' . max(1, $days) . ' days -1 day'));

    $pdo->prepare("UPDATE chamas SET plan_expires_at = ?, status = 'active', suspension_reason = '' WHERE id = ?")
        ->execute([$end, $chamaId]);

    return $end;
}

/**
 * Record a successful subscription payment: store it, extend the plan and
 * restore access if the group had been suspended for non-payment.
 */
function record_subscription_payment(array $data): int
{
    global $pdo;

    $months = max(1, (int)($data['months'] ?? 1));
    $stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$data['chama_id']]);
    $chama = $stmt->fetch();
    if (!$chama) {
        return 0;
    }

    // Idempotency: never record the same M-Pesa receipt twice.
    if (!empty($data['mpesa_receipt'])) {
        $stmt = $pdo->prepare("SELECT id FROM subscription_payments WHERE mpesa_receipt = ? AND status = 'paid' LIMIT 1");
        $stmt->execute([$data['mpesa_receipt']]);
        if ($existing = $stmt->fetchColumn()) {
            return (int)$existing;
        }
    }

    $period = subscription_next_period($chama, $months);

    $stmt = $pdo->prepare(
        "INSERT INTO subscription_payments
            (chama_id, amount, months, period_start, period_end, method, status,
             checkout_request_id, merchant_request_id, mpesa_receipt, mpesa_phone,
             paid_at, notes, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?, ?, NOW(), ?, ?, NOW())"
    );
    $stmt->execute([
        (int)$data['chama_id'],
        (float)$data['amount'],
        $months,
        $period['start'],
        $period['end'],
        $data['method'] ?? 'mpesa_stk',
        $data['checkout_request_id'] ?? null,
        $data['merchant_request_id'] ?? null,
        $data['mpesa_receipt'] ?? null,
        $data['mpesa_phone'] ?? null,
        trim((string)($data['notes'] ?? '')),
        $data['created_by'] ?? null,
    ]);
    $paymentId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "UPDATE chamas SET plan_expires_at = ?, status = 'active', suspension_reason = '' WHERE id = ?"
    )->execute([$period['end'], (int)$data['chama_id']]);

    return $paymentId;
}

/** Create a pending payment row before sending an STK push. */
function create_pending_payment(int $chamaId, float $amount, int $months, string $checkoutId, string $phone, ?int $createdBy, string $method = 'mpesa_stk'): int
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ? LIMIT 1');
    $stmt->execute([$chamaId]);
    $chama = $stmt->fetch();
    $period = subscription_next_period($chama ?: ['plan_expires_at' => null], $months);

    $stmt = $pdo->prepare(
        "INSERT INTO subscription_payments
            (chama_id, amount, months, period_start, period_end, method, status,
             checkout_request_id, mpesa_phone, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW())"
    );
    $stmt->execute([$chamaId, $amount, $months, $period['start'], $period['end'], $method, $checkoutId, $phone, $createdBy]);
    return (int)$pdo->lastInsertId();
}

function mark_payment_failed(int $paymentId, string $reason): void
{
    global $pdo;
    $pdo->prepare("UPDATE subscription_payments SET status = 'failed', notes = ? WHERE id = ? AND status = 'pending'")
        ->execute([substr($reason, 0, 250), $paymentId]);
}

/**
 * Turn a pending STK payment into a paid one: stores the receipt, extends the
 * plan and restores access. Safe to call twice for the same payment.
 */
function complete_pending_payment(int $paymentId, string $receipt, ?string $phone, ?float $amount): bool
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM subscription_payments WHERE id = ? LIMIT 1');
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        return false;
    }
    if ($payment['status'] === 'paid') {
        return true; // already processed
    }

    // Guard against a receipt being reused on a different payment.
    if ($receipt !== '') {
        $stmt = $pdo->prepare("SELECT id FROM subscription_payments WHERE mpesa_receipt = ? AND id <> ? LIMIT 1");
        $stmt->execute([$receipt, $paymentId]);
        if ($stmt->fetchColumn()) {
            mark_payment_failed($paymentId, 'Duplicate M-Pesa receipt ' . $receipt);
            return false;
        }
    }

    $months = max(1, (int)($payment['months'] ?? 1));
    $stmt = $pdo->prepare('SELECT * FROM chamas WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$payment['chama_id']]);
    $chama = $stmt->fetch();
    if (!$chama) {
        return false;
    }

    // Re-compute the period from now, in case time passed while awaiting payment.
    $period = subscription_next_period($chama, $months);

    $pdo->prepare(
        "UPDATE subscription_payments
            SET status = 'paid', paid_at = NOW(), mpesa_receipt = ?, mpesa_phone = COALESCE(?, mpesa_phone),
                amount = COALESCE(?, amount), period_start = ?, period_end = ?
          WHERE id = ?"
    )->execute([
        $receipt !== '' ? $receipt : null,
        $phone ?: null,
        $amount !== null && $amount > 0 ? $amount : null,
        $period['start'],
        $period['end'],
        $paymentId,
    ]);

    $pdo->prepare("UPDATE chamas SET plan_expires_at = ?, status = 'active', suspension_reason = '' WHERE id = ?")
        ->execute([$period['end'], (int)$payment['chama_id']]);

    return true;
}

// ---------------------------------------------------------------------------
// Automatic suspension
// ---------------------------------------------------------------------------

/**
 * Suspend every chama that is past expiry + grace. Runs on each page load
 * (cheap: one indexed query) and can also be triggered by cron/subscriptions.php.
 *
 * @return int number of chamas suspended by this run
 */
function enforce_subscriptions(): int
{
    global $pdo;

    $cutoff = date('Y-m-d', strtotime('-' . max(0, (int)SUBSCRIPTION_GRACE_DAYS) . ' days'));

    $stmt = $pdo->prepare(
        "SELECT id FROM chamas
          WHERE status = 'active'
            AND monthly_fee > 0
            AND plan_expires_at IS NOT NULL
            AND plan_expires_at < ?"
    );
    $stmt->execute([$cutoff]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $id) {
        $pdo->prepare("UPDATE chamas SET status = 'suspended', suspension_reason = 'nonpayment' WHERE id = ?")
            ->execute([(int)$id]);
        mpesa_log('in', 'auto-suspend', ['chama_id' => (int)$id, 'reason' => 'subscription expired past grace period']);
    }

    return count($ids);
}

/**
 * Is the logged-in user locked out of everything except paying their bill?
 * Only admins/treasurers of a chama suspended for non-payment get restricted
 * access - members stay fully blocked.
 */
function subscription_restricted_mode(): bool
{
    if (!is_logged_in() || is_superadmin()) {
        return false;
    }
    $chama = current_chama();
    if (!$chama) {
        return false;
    }
    if ($chama['status'] === 'active') {
        return false;
    }
    if (($chama['suspension_reason'] ?? '') !== 'nonpayment') {
        return false;
    }
    $user = current_user();
    return in_array($user['role'] ?? '', ['admin', 'treasurer'], true);
}

/** Pages a restricted user may still open. */
function subscription_allowed_paths(): array
{
    return [
        'account/subscription.php',
        'account/password.php',
        'billing/pay.php',
        'billing/payments.php',
        'mpesa/status.php',
        'logout.php',
    ];
}

/** Redirect a restricted user to their billing page unless they are already there. */
function enforce_subscription_gate(): void
{
    if (!subscription_restricted_mode()) {
        return;
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $path = str_replace('\\', '/', (string)$path);
    foreach (subscription_allowed_paths() as $allowed) {
        if (str_ends_with($path, '/' . $allowed)) {
            return;
        }
    }
    if (empty($_SESSION['flash_error'])) {
        $_SESSION['flash_error'] = 'Your group is suspended because its subscription expired. Pay to restore full access.';
    }
    header('Location: ' . url('account/subscription.php'));
    exit;
}

/** The phone number a chama should be billed on. */
function chama_billing_phone(array $chama): string
{
    global $pdo;
    if (!empty($chama['billing_phone'])) {
        return (string)$chama['billing_phone'];
    }
    if (!empty($chama['phone'])) {
        return (string)$chama['phone'];
    }
    // Fall back to the founding admin's member phone.
    $stmt = $pdo->prepare(
        "SELECT m.phone FROM users u
           JOIN members m ON m.id = u.member_id
          WHERE u.chama_id = ? AND u.role = 'admin' AND m.phone <> ''
          ORDER BY u.id ASC LIMIT 1"
    );
    $stmt->execute([(int)$chama['id']]);
    $phone = $stmt->fetchColumn();
    return $phone ? (string)$phone : '';
}

/**
 * Start a subscription payment for a chama: creates the pending record and
 * fires the STK push (or simulates it when Daraja is not configured).
 *
 * @return array{ok:bool,payment_id:int,checkout_id:string,simulated:bool,amount:float,period:array,error:string}
 */
function initiate_subscription_stk(array $chama, string $phone, ?int $userId, int $months = 0): array
{
    global $pdo;

    $months = $months > 0 ? $months : max(1, (int)SUBSCRIPTION_BILLING_MONTHS);
    $amount = subscription_amount_due($chama) * $months;
    $period = subscription_next_period($chama, $months);
    $fail = function (string $message) use ($period, $amount) {
        return ['ok' => false, 'payment_id' => 0, 'checkout_id' => '', 'simulated' => false,
                'amount' => $amount, 'period' => $period, 'error' => $message];
    };

    if ($amount < 1) {
        return $fail('This group has no monthly fee set, so there is nothing to bill. Set a fee in Chamas -> Edit first.');
    }

    $normalized = mpesa_normalize_phone($phone);
    if ($normalized === '') {
        return $fail('Enter a valid Safaricom number to receive the M-Pesa prompt, e.g. 0712 345 678.');
    }

    $accountRef = 'CHAMA' . (int)$chama['id'];
    $description = 'Subscription ' . $period['start'] . ' to ' . $period['end'];

    $result = mpesa_stk_push($normalized, $amount, $accountRef, $description);
    if (!$result['ok']) {
        return $fail($result['error']);
    }

    $paymentId = create_pending_payment(
        (int)$chama['id'],
        $amount,
        $months,
        (string)$result['checkout_request_id'],
        $normalized,
        $userId
    );

    return [
        'ok' => true,
        'payment_id' => $paymentId,
        'checkout_id' => (string)$result['checkout_request_id'],
        'simulated' => !empty($result['simulated']),
        'amount' => $amount,
        'period' => $period,
        'error' => '',
    ];
}

/** Platform revenue collected in a period. */
function subscription_revenue(string $from, string $to): float
{
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM subscription_payments
          WHERE status = 'paid' AND DATE(paid_at) BETWEEN ? AND ?"
    );
    $stmt->execute([$from, $to]);
    return (float)$stmt->fetchColumn();
}
