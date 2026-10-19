<?php
/**
 * includes/sms.php - SMS engine with pluggable providers.
 *
 * Works out of the box in SIMULATION mode: every message is written to
 * sms_logs and shown in SMS > Log, so you can build and demo the whole
 * reminder/receipt flow without spending a shilling.
 *
 * To send real messages, copy config/sms-secrets.example.php to
 * config/sms-secrets.php and paste your Africa's Talking credentials
 * (free sandbox account at africastalking.com - no card needed).
 *
 *   sms_send(['chama_id'=>2, 'member_id'=>5, 'phone'=>'0722000111',
 *             'message'=>'...', 'purpose'=>'receipt']);
 */

require_once __DIR__ . '/../config/db.php';

if (!function_exists('sms_config')) {
    /** Provider settings; the secrets file is optional and git-ignored. */
    function sms_config(): array
    {
        $cfg = [
            'provider'  => 'simulation',
            'username'  => '',
            'api_key'   => '',
            'sender_id' => '',
        ];
        $file = __DIR__ . '/../config/sms-secrets.php';
        if (is_file($file)) {
            $loaded = include $file;
            if (is_array($loaded)) {
                $cfg = array_merge($cfg, $loaded);
            }
        }
        if ($cfg['provider'] !== 'africastalking') {
            $cfg['provider'] = 'simulation';
        }
        return $cfg;
    }
}

if (!function_exists('sms_normalize_phone')) {
    /** Kenyan numbers to 2547XXXXXXXX / 2541XXXXXXXX; '' when unusable. */
    function sms_normalize_phone(string $phone): string
    {
        $p = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        $p = ltrim($p, '+');
        if ($p === '') {
            return '';
        }
        if (str_starts_with($p, '00254')) {
            $p = '254' . substr($p, 5);
        } elseif (str_starts_with($p, '07') || str_starts_with($p, '01')) {
            $p = '254' . substr($p, 1);
        } elseif (str_starts_with($p, '7') || str_starts_with($p, '1')) {
            if (strlen($p) === 9) {
                $p = '254' . $p;
            }
        }
        return preg_match('/^254(7|1)\d{8}$/', $p) ? $p : '';
    }
}

if (!function_exists('sms_log_entry')) {
    function sms_log_entry(array $args, string $status, string $provider, ?string $messageId, string $error = ''): int
    {
        global $pdo;
        $stmt = $pdo->prepare(
            'INSERT INTO sms_logs (chama_id, member_id, phone, purpose, message, provider, status, provider_message_id, error, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $args['chama_id'] ?? null,
            $args['member_id'] ?? null,
            (string)($args['phone'] ?? ''),
            (string)($args['purpose'] ?? 'notice'),
            str_cut((string)($args['message'] ?? ''), 480),
            $provider,
            $status,
            $messageId,
            str_cut($error, 255),
            $args['created_by'] ?? (function_exists('current_user') ? (current_user()['id'] ?? null) : null),
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('sms_send')) {
    /**
     * Send one SMS. Returns ['ok'=>bool, 'status'=>..., 'id'=>log id, 'error'=>...].
     * Never throws: messaging must not break the payment/contribution flow.
     */
    function sms_send(array $args): array
    {
        global $pdo;
        $cfg = sms_config();
        $rawPhone = (string)($args['phone'] ?? '');
        $phone = sms_normalize_phone($rawPhone);
        $message = trim((string)($args['message'] ?? ''));
        if ($message === '') {
            return ['ok' => false, 'status' => 'failed', 'id' => 0, 'error' => 'Empty message.'];
        }
        if ($phone === '') {
            $id = sms_log_entry($args, 'failed', $cfg['provider'], null, 'Invalid phone number: ' . $rawPhone);
            return ['ok' => false, 'status' => 'failed', 'id' => $id, 'error' => 'Invalid phone number.'];
        }

        if ($cfg['provider'] === 'simulation') {
            $id = sms_log_entry($args, 'simulated', 'simulation', 'SIM' . strtoupper(bin2hex(random_bytes(4))), '');
            return ['ok' => true, 'status' => 'simulated', 'id' => $id, 'error' => ''];
        }

        // Live SMS is a PREPAID add-on: never promised unlimited, always
        // deducted from the group's credit balance before sending.
        $parts = max(1, (int)ceil(strlen($message) / 160));
        $chId = (int)($args['chama_id'] ?? 0);
        if ($chId > 0) {
            $st = $pdo->prepare('UPDATE chamas SET sms_balance = sms_balance - ? WHERE id = ? AND sms_balance >= ?');
            $st->execute([$parts, $chId, $parts]);
            if ($st->rowCount() === 0) {
                $id = sms_log_entry($args, 'failed', $cfg['provider'], null, 'No SMS credit left for this group');
                return ['ok' => false, 'status' => 'failed', 'id' => $id, 'error' => 'No SMS credit. Top up via the platform operator.'];
            }
        }

        // ---- Africa's Talking -------------------------------------------------
        $id = sms_log_entry($args, 'queued', 'africastalking', null, '');
        $payload = http_build_query([
            'username' => $cfg['username'],
            'from'     => $cfg['sender_id'] !== '' ? $cfg['sender_id'] : null,
            'to'       => $phone,
            'message'  => $message,
        ]);
        $ch = curl_init('https://api.africastalking.com/version1/messaging');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'apiKey: ' . $cfg['api_key'],
            ],
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $data = is_string($body) ? json_decode($body, true) : null;
        $recipients = $data['SMSMessageData']['Recipients'] ?? [];
        $first = is_array($recipients) ? ($recipients[0] ?? null) : null;
        $sent = is_array($first) && in_array(strtoupper((string)($first['status'] ?? '')), ['SUCCESS', 'SENT'], true);

        if ($sent) {
            $pdo->prepare("UPDATE sms_logs SET status='sent', provider_message_id=?, error='' WHERE id=?")
                ->execute([(string)($first['messageId'] ?? ''), $id]);
            return ['ok' => true, 'status' => 'sent', 'id' => $id, 'error' => ''];
        }

        $err = $curlErr !== '' ? $curlErr : ('HTTP ' . $http . ': ' . substr((string)($first['errorMessage'] ?? ($body ?: 'Unknown error')), 0, 200));
        $pdo->prepare("UPDATE sms_logs SET status='failed', error=? WHERE id=?")->execute([str_cut($err, 255), $id]);
        return ['ok' => false, 'status' => 'failed', 'id' => $id, 'error' => $err];
    }
}

if (!function_exists('sms_receipts_enabled')) {
    function sms_receipts_enabled(array $chama): bool
    {
        return (int)($chama['sms_receipts'] ?? 1) === 1;
    }
}

if (!function_exists('sms_payment_receipt')) {
    /**
     * Confirmation SMS after money lands. Called from the payment engine and
     * from manual contribution entry; silently skipped when disabled.
     */
    function sms_payment_receipt(array $chama, array $member, string $phone, string $kindLabel, float $amount, string $reference, string $extra = ''): void
    {
        if (!sms_receipts_enabled($chama)) {
            return;
        }
        $name = $member['full_name'] ?? '';
        $first = trim(strtok($name, ' ') ?: $name);
        $msg = sprintf(
            '%s: KSh %s received for %s. Ref %s. %sAsante, %s.',
            $chama['name'] ?? 'Your chama',
            number_format($amount, 2),
            $kindLabel,
            $reference,
            $extra,
            $chama['name'] ?? 'your chama'
        );
        sms_send([
            'chama_id'  => $chama['id'] ?? null,
            'member_id' => $member['id'] ?? null,
            'phone'     => $phone,
            'purpose'   => 'receipt',
            'message'   => str_cut($msg, 480),
        ]);
    }
}

if (!function_exists('sms_sim_mode')) {
    function sms_sim_mode(): bool
    {
        return sms_config()['provider'] === 'simulation';
    }
}
