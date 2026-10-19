<?php
/**
 * M-Pesa Daraja helpers: OAuth, STK Push, status query, request logging.
 *
 * Works in two modes:
 *   LIVE       - real Daraja API call (needs config/mpesa-secrets.php)
 *   SIMULATE   - no credentials: creates the same pending payment record so the
 *                whole flow can be demoed and tested locally, with a button to
 *                "complete" the payment exactly like Safaricom's callback would.
 */

require_once __DIR__ . '/../config/mpesa.php';

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

/** Normalise any Kenyan phone format to 2547XXXXXXXX. */
function mpesa_normalize_phone(string $phone): string
{
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if ($phone === '' || $phone === null) {
        return '';
    }
    if (str_starts_with($phone, '+')) {
        $phone = substr($phone, 1);
    }
    if (str_starts_with($phone, '0')) {
        $phone = '254' . substr($phone, 1);
    } elseif (str_starts_with($phone, '7') || str_starts_with($phone, '1')) {
        $phone = '254' . $phone;
    }
    return preg_match('/^254[17]\d{8}$/', $phone) ? $phone : '';
}

/** Mask a phone number for display: 2547XX XXX149 -> 07** ***149 */
function mpesa_mask_phone(string $phone): string
{
    $p = mpesa_normalize_phone($phone);
    if ($p === '') {
        return e($phone);
    }
    $local = '0' . substr($p, 3);
    return substr($local, 0, 4) . '** ***' . substr($local, -3);
}

function mpesa_http_request(string $url, array $payload, ?string $token = null): array
{
    if (!function_exists('curl_init')) {
        return [
            'status' => 0,
            'body' => '',
            'json' => [],
            'error' => 'The PHP cURL extension is not enabled. Open php.ini and uncomment "extension=curl", then restart Apache.',
        ];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'json' => is_string($body) ? (json_decode($body, true) ?: []) : [],
        'error' => $error,
    ];
}

/** Log every Daraja exchange so problems can be diagnosed later. */
function mpesa_log(string $direction, string $endpoint, array $data, ?string $checkoutId = null, ?string $receipt = null): void
{
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO mpesa_logs (direction, endpoint, checkout_request_id, receipt_no, payload, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $direction,
            substr($endpoint, 0, 120),
            $checkoutId,
            $receipt,
            json_encode($data, JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $ignored) {
        // Logging must never break a payment flow.
    }
}

// ---------------------------------------------------------------------------
// OAuth token (cached on disk for its lifetime)
// ---------------------------------------------------------------------------
function mpesa_access_token(bool $forceRefresh = false): array
{
    if (mpesa_is_simulation()) {
        return ['ok' => false, 'token' => '', 'error' => 'M-Pesa credentials are not configured (simulation mode).'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'token' => '', 'error' => 'The PHP cURL extension is not enabled. Open php.ini, uncomment "extension=curl", and restart Apache.'];
    }

    $cacheFile = mpesa_cache_dir() . '/mpesa_token_' . md5(MPESA_ENV . MPESA_SHORTCODE . MPESA_CONSUMER_KEY) . '.json';
    if (!$forceRefresh && is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (!empty($cached['token']) && !empty($cached['expires_at']) && $cached['expires_at'] > time() + 60) {
            return ['ok' => true, 'token' => $cached['token'], 'error' => ''];
        }
    }

    $ch = curl_init(MPESA_OAUTH_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERPWD => MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $json = is_string($body) ? (json_decode($body, true) ?: []) : [];
    mpesa_log('out', 'oauth', ['http_status' => $status, 'response' => $json, 'error' => $curlError]);

    if ($status === 200 && !empty($json['access_token'])) {
        $expiresIn = (int)($json['expires_in'] ?? 3599);
        @file_put_contents($cacheFile, json_encode([
            'token' => $json['access_token'],
            'expires_at' => time() + max(60, $expiresIn - 120),
        ]));
        return ['ok' => true, 'token' => $json['access_token'], 'error' => ''];
    }

    $message = (string)($json['errorMessage'] ?? $json['error_description'] ?? '');
    if ($message === '') {
        $message = $curlError !== '' ? $curlError : ('HTTP ' . $status);
    }
    $hint = '';
    if (in_array($status, [400, 401, 403], true)) {
        $hint = ' Check that consumer_key and consumer_secret in config/mpesa-secrets.php are correct for this environment'
            . ' (sandbox keys only work when environment = sandbox; live keys only when environment = production).';
    } elseif ($status === 0) {
        $hint = ' The server could not reach Safaricom - check the internet connection, firewall or proxy on this machine.';
    } elseif ($status === 404) {
        $hint = ' Wrong API address - verify "environment" in config/mpesa-secrets.php.';
    }
    return ['ok' => false, 'token' => '', 'error' => 'Daraja authentication failed: ' . $message . $hint];
}

// ---------------------------------------------------------------------------
// STK Push
// ---------------------------------------------------------------------------
/**
 * Which M-Pesa mode are we in?
 *   simulation - no Daraja credentials: nothing real happens, clearly labelled
 *   sandbox    - Safaricom test environment: test money only
 *   production - real money
 */
function mpesa_mode(): string
{
    if (mpesa_is_simulation()) {
        return 'simulation';
    }
    return MPESA_ENV === 'production' ? 'production' : 'sandbox';
}

/**
 * A group's OWN collection route (their Paybill/Till + passkey).
 * Group money must settle to the group's account, never to the platform's.
 */
function mpesa_chama_route(int $chamaId): ?array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT pay_shortcode, pay_passkey, pay_verified, pay_verified_at FROM chamas WHERE id = ? LIMIT 1');
    $stmt->execute([$chamaId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * May this group receive live STK collections?
 *   simulation     - demo mode: allowed, clearly labelled, no money moves
 *   ready          - verified group route configured: live STK to THEIR account
 *   setup_required - no route: refuse live STK, fall back to manual recording
 *   unverified     - route entered but not yet ownership-verified by the operator
 */
function mpesa_group_route_status(array $chama): array
{
    if (mpesa_is_simulation()) {
        return ['state' => 'simulation', 'route' => [], 'message' => 'Demo mode: no real money moves.'];
    }
    $route = mpesa_chama_route((int)($chama['id'] ?? 0));
    $shortcode = trim((string)($route['pay_shortcode'] ?? ''));
    $passkey = trim((string)($route['pay_passkey'] ?? ''));
    if ($shortcode === '' || $passkey === '') {
        return [
            'state' => 'setup_required',
            'route' => [],
            'message' => 'Live M-Pesa collection is not enabled for this group yet (no Paybill/Till route configured). Record payments manually, or set the group route under Account > M-Pesa collection route.',
        ];
    }
    if ((int)($route['pay_verified'] ?? 0) !== 1) {
        return [
            'state' => 'unverified',
            'route' => [],
            'message' => 'This group’s M-Pesa route is waiting for ownership verification by the platform operator. Record payments manually meanwhile.',
        ];
    }
    return ['state' => 'ready', 'route' => ['shortcode' => $shortcode, 'passkey' => $passkey], 'message' => ''];
}

function mpesa_stk_payload(string $phone, float $amount, string $accountRef, string $description, array $route = []): array
{
    $route = array_merge(['shortcode' => MPESA_SHORTCODE, 'passkey' => MPESA_PASSKEY], $route);
    $timestamp = date('YmdHis');
    $password = base64_encode($route['shortcode'] . $route['passkey'] . $timestamp);

    return [
        'BusinessShortCode' => $route['shortcode'],
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => MPESA_TRANSACTION_TYPE,
        'Amount' => (string)max(1, (int)round($amount)),
        'PartyA' => $phone,
        'PartyB' => $route['shortcode'],
        'PhoneNumber' => $phone,
        'CallBackURL' => mpesa_callback_url(),
        'AccountReference' => $accountRef !== '' ? $accountRef : 'CHAMA',
        'TransactionDesc' => $description !== '' ? $description : 'Subscription payment',
    ];
}

/**
 * Send an STK push. Returns:
 *   ok                  - request accepted (or simulated)
 *   checkout_request_id - use it to match the callback later
 *   simulated           - true when no credentials are configured
 *   error               - human readable failure reason
 */
function mpesa_stk_push(string $phone, float $amount, string $accountRef, string $description, array $route = []): array
{
    $phone = mpesa_normalize_phone($phone);
    if ($phone === '') {
        return ['ok' => false, 'checkout_request_id' => '', 'simulated' => false, 'error' => 'Enter a valid Safaricom number, e.g. 0712 345 678.'];
    }
    if ($amount < 1) {
        return ['ok' => false, 'checkout_request_id' => '', 'simulated' => false, 'error' => 'Amount must be at least KES 1.'];
    }

    // --- Simulation mode: no Daraja credentials configured ------------------
    if (mpesa_is_simulation()) {
        $fakeId = 'SIM-' . strtoupper(bin2hex(random_bytes(6))) . '-' . time();
        mpesa_log('out', 'stkpush:simulated', [
            'phone' => $phone, 'amount' => $amount,
            'account_reference' => $accountRef, 'checkout_request_id' => $fakeId,
        ], $fakeId);
        return [
            'ok' => true,
            'checkout_request_id' => $fakeId,
            'simulated' => true,
            'error' => '',
        ];
    }

    // --- Live Daraja call ---------------------------------------------------
    $auth = mpesa_access_token();
    if (!$auth['ok']) {
        return ['ok' => false, 'checkout_request_id' => '', 'simulated' => false, 'error' => $auth['error']];
    }

    $payload = mpesa_stk_payload($phone, $amount, $accountRef, $description, $route);
    $res = mpesa_http_request(MPESA_STK_URL, $payload, $auth['token']);
    $json = $res['json'];

    // Never log the generated password.
    $logPayload = $payload;
    $logPayload['Password'] = '[redacted]';
    mpesa_log('out', 'stkpush', [
        'request' => $logPayload,
        'http_status' => $res['status'],
        'response' => $json,
        'curl_error' => $res['error'],
    ], $json['CheckoutRequestID'] ?? null);

    if ($res['status'] === 200 && !empty($json['ResponseCode']) && (string)$json['ResponseCode'] === '0') {
        return [
            'ok' => true,
            'checkout_request_id' => (string)$json['CheckoutRequestID'],
            'merchant_request_id' => (string)($json['MerchantRequestID'] ?? ''),
            'simulated' => false,
            'error' => '',
        ];
    }

    $message = (string)($json['errorMessage'] ?? $json['ResponseDescription'] ?? '');
    if ($message === '') {
        $message = $res['error'] !== '' ? $res['error'] : ('HTTP ' . $res['status']);
    }
    $hint = '';
    if (in_array($res['status'], [400, 401, 403], true)) {
        $hint = ' Usually caused by a wrong shortcode, wrong passkey, a mismatched transaction_type'
            . ' (Paybill vs Till), or a stale OAuth token - delete storage/mpesa_token_*.json and retry.';
    }
    return ['ok' => false, 'checkout_request_id' => '', 'simulated' => false, 'error' => 'M-Pesa request failed: ' . $message . $hint];
}

/** Query Daraja for the result of an STK push (used to confirm pending payments). */
function mpesa_query_stk(string $checkoutRequestId): array
{
    if ($checkoutRequestId === '' || mpesa_is_simulation()) {
        return ['ok' => false, 'paid' => false, 'error' => 'Not available in simulation mode.'];
    }
    $auth = mpesa_access_token();
    if (!$auth['ok']) {
        return ['ok' => false, 'paid' => false, 'error' => $auth['error']];
    }
    $res = mpesa_http_request(MPESA_STK_QUERY_URL, [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password' => base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . date('YmdHis')),
        'Timestamp' => date('YmdHis'),
        'CheckoutRequestID' => $checkoutRequestId,
    ], $auth['token']);

    $json = $res['json'];
    mpesa_log('out', 'stkquery', ['request_id' => $checkoutRequestId, 'http_status' => $res['status'], 'response' => $json], $checkoutRequestId);

    $paid = isset($json['ResultCode']) && (int)$json['ResultCode'] === 0;
    return [
        'ok' => $res['status'] === 200,
        'paid' => $paid,
        'receipt' => $json['MpesaReceiptNumber'] ?? null,
        'raw' => $json,
        'error' => $paid ? '' : ($json['errorMessage'] ?? $json['ResultDesc'] ?? 'Not paid yet'),
    ];
}
