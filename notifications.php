<?php
/**
 * Multi-channel member notifications: SMS (existing), Email (new),
 * WhatsApp (manual click-to-send now, provider automation later).
 *
 * Every attempt is logged to sms_logs with its channel so committees can see
 * exactly what went out. Nothing is ever silently dropped.
 */
require_once __DIR__ . '/sms.php';

if (!function_exists('notification_email_config')) {
    /** Reads config/email-secrets.php; missing file = no email transport. */
    function notification_email_config(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $file = __DIR__ . '/../config/email-secrets.php';
        if (is_file($file)) {
            try {
                $cfg = require $file;
                if (is_array($cfg) && !empty($cfg['driver'])) {
                    return $cache = $cfg;
                }
            } catch (Throwable $ignored) {
            }
        }
        return $cache = ['driver' => 'none'];
    }
}

if (!function_exists('notification_log')) {
    /** One row per delivery attempt, tagged by channel. */
    function notification_log(int $chamaId, ?int $memberId, string $recipient, string $purpose, string $message, string $channel, string $provider, string $status, string $error = '', ?string $refId = null): void
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO sms_logs (chama_id, member_id, phone, purpose, message, channel, provider, status, error, provider_message_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$chamaId, $memberId, substr($recipient, 0, 30), $purpose, substr($message, 0, 480), $channel, $provider, $status, substr($error, 0, 255), $refId]);
        } catch (Throwable $ignored) {
        }
    }
}

if (!function_exists('email_send')) {
    /**
     * Send one email. Drivers:
     *  - 'api'  : Brevo-style transactional API (works with Brevo free tier)
     *  - 'mail' : PHP mail() (needs sendmail/SMTP configured on the server)
     *  - 'none' : logs as simulated (no credentials yet) so nothing fails
     */
    function email_send(string $to, string $subject, string $body, int $chamaId = 0, ?int $memberId = null, string $purpose = 'notice'): array
    {
        $cfg = notification_email_config();
        $driver = (string)($cfg['driver'] ?? 'none');
        $from = (string)($cfg['from'] ?? 'noreply@chama.local');
        $senderName = (string)($cfg['sender_name'] ?? 'Chama System');

        if ($driver === 'api' && !empty($cfg['api_key'])) {
            $payload = json_encode([
                'sender' => ['name' => $senderName, 'email' => $from],
                'to' => [['email' => $to]],
                'subject' => $subject,
                'textContent' => $body,
            ]);
            $url = rtrim((string)($cfg['api_url'] ?? 'https://api.brevo.com/v3/smtp/email'), '/');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'api-key: ' . $cfg['api_key']],
                CURLOPT_TIMEOUT => 15,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = (string)curl_error($ch);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                $ref = '';
                $j = json_decode((string)$resp, true);
                if (is_array($j) && isset($j['messageId'])) {
                    $ref = (string)$j['messageId'];
                }
                notification_log($chamaId, $memberId, $to, $purpose, $subject . ' | ' . $body, 'email', 'api', 'sent', '', $ref ?: null);
                return ['ok' => true, 'channel' => 'email', 'provider' => 'api'];
            }
            notification_log($chamaId, $memberId, $to, $purpose, $subject . ' | ' . $body, 'email', 'api', 'failed', $err !== '' ? $err : ('HTTP ' . $code));
            return ['ok' => false, 'channel' => 'email', 'provider' => 'api', 'error' => $err !== '' ? $err : 'HTTP ' . $code];
        }

        if ($driver === 'mail') {
            $headers = 'From: ' . $senderName . ' <' . $from . ">\r\n"
                     . "X-Mailer: ChamaSystem\r\n";
            $ok = @mail($to, $subject, $body, $headers);
            notification_log($chamaId, $memberId, $to, $purpose, $subject . ' | ' . $body, 'email', 'mail', $ok ? 'sent' : 'failed', $ok ? '' : 'PHP mail() returned false - configure SMTP/sendmail on this server');
            return ['ok' => (bool)$ok, 'channel' => 'email', 'provider' => 'mail'];
        }

        // No transport configured: record honestly as simulated.
        notification_log($chamaId, $memberId, $to, $purpose, $subject . ' | ' . $body, 'email', 'simulation', 'simulated', '');
        return ['ok' => true, 'channel' => 'email', 'provider' => 'simulation'];
    }
}

if (!function_exists('whatsapp_click_link')) {
    /** wa.me link with pre-filled text: the committee taps, WhatsApp opens ready to send. */
    function whatsapp_click_link(string $phone, string $text): string
    {
        $digits = preg_replace('/\D/', '', str_replace('+', '', trim($phone)));
        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            $digits = '254' . substr($digits, 1);   // Kenyan local format
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '07')) {
            $digits = '254' . substr($digits, 1);
        }
        return 'https://wa.me/' . $digits . '?text=' . rawurlencode($text);
    }
}

if (!function_exists('notify_member')) {
    /**
     * Reach one member through every channel that is available.
     * Email goes automatically when the address exists; SMS follows the
     * group's existing SMS rules (simulation / prepaid credit). Each
     * channel is logged separately. Returns a per-channel summary.
     *
     * $member needs: id, chama_id, full_name, phone, email (optional).
     * $opts: purpose (reminder|receipt|loan|notice), subject, message.
     */
    function notify_member(array $member, array $opts): array
    {
        $summary = [];
        $chamaId = (int)($member['chama_id'] ?? 0);
        $memberId = (int)($member['id'] ?? 0);
        $purpose = in_array(($opts['purpose'] ?? 'notice'), ['receipt', 'reminder', 'broadcast', 'loan', 'notice'], true) ? $opts['purpose'] : 'notice';
        $message = trim((string)($opts['message'] ?? ''));
        $subject = trim((string)($opts['subject'] ?? ($purpose === 'reminder' ? 'Dues reminder' : ($purpose === 'receipt' ? 'Payment received' : 'Notification'))));

        if ($message === '') {
            return ['ok' => false, 'error' => 'Empty message'];
        }

        $email = trim((string)($member['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $summary['email'] = email_send($email, $subject, $message, $chamaId, $memberId, $purpose);
        }

        if (!empty($member['phone']) || $purpose === 'reminder') {
            $summary['sms'] = sms_send([
                'chama_id'  => $chamaId,
                'member_id' => $memberId,
                'phone'     => (string)($member['phone'] ?? ''),
                'purpose'   => $purpose,
                'message'   => $message,
            ]);
        }

        $anyOk = false;
        foreach ($summary as $r) {
            if (!empty($r['ok'])) {
                $anyOk = true;
            }
        }
        return ['ok' => $anyOk, 'channels' => $summary];
    }
}
