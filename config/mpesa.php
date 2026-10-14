<?php
/**
 * M-Pesa (Safaricom Daraja) configuration.
 *
 * Credentials are NOT stored in this file. They live in config/mpesa-secrets.php
 * (which is excluded from git) or in environment variables. Copy
 * config/mpesa-secrets.example.php to config/mpesa-secrets.php and fill it in.
 */

require_once __DIR__ . '/app.php';

// Load secrets first (the file may also choose the environment).
$secretsFile = __DIR__ . '/mpesa-secrets.php';
$secrets = is_file($secretsFile) ? (array)require $secretsFile : [];

// ---------------------------------------------------------------------------
// Environment: 'sandbox' (free test credentials) or 'production' (real money).
// ---------------------------------------------------------------------------
if (!defined('MPESA_ENV')) {
    $env = getenv('MPESA_ENV');
    if ($env === false || $env === '') {
        $env = $secrets['environment'] ?? 'sandbox';
    }
    define('MPESA_ENV', $env === 'production' ? 'production' : 'sandbox');
}

function mpesa_secret(string $key, string $default = ''): string
{
    global $secrets;
    if (!empty($secrets[$key])) {
        return (string)$secrets[$key];
    }
    $env = getenv('MPESA_' . strtoupper($key));
    if ($env !== false && $env !== '') {
        return (string)$env;
    }
    return $default;
}

$isSandbox = MPESA_ENV === 'sandbox';

// ---------------------------------------------------------------------------
// API endpoints
// ---------------------------------------------------------------------------
define('MPESA_BASE_URL', $isSandbox
    ? 'https://sandbox.safaricom.co.ke'
    : 'https://api.safaricom.co.ke');

define('MPESA_OAUTH_URL', MPESA_BASE_URL . '/oauth/v1/generate?grant_type=client_credentials');
define('MPESA_STK_URL', MPESA_BASE_URL . '/mpesa/stkpush/v1/processrequest');
define('MPESA_STK_QUERY_URL', MPESA_BASE_URL . '/mpesa/stkpushquery/v1/query');

// ---------------------------------------------------------------------------
// Business details
// ---------------------------------------------------------------------------
// Sandbox test till/paybill provided by Safaricom for everyone: 174379.
define('MPESA_SHORTCODE', mpesa_secret('shortcode', $isSandbox ? '174379' : ''));

// The sandbox passkey is published by Safaricom and works with shortcode 174379.
define('MPESA_PASSKEY', mpesa_secret('passkey', $isSandbox
    ? 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919'
    : ''));

define('MPESA_CONSUMER_KEY', mpesa_secret('consumer_key'));
define('MPESA_CONSUMER_SECRET', mpesa_secret('consumer_secret'));

// CustomerPayBillOnline = Paybill, CustomerBuyGoodsOnline = Till number.
define('MPESA_TRANSACTION_TYPE', mpesa_secret('transaction_type', 'CustomerPayBillOnline'));

// ---------------------------------------------------------------------------
// Callback (where Safaricom POSTs the payment result)
// ---------------------------------------------------------------------------
// Public HTTPS address of THIS installation. When running on XAMPP locally you
// must point this at your tunnel URL (ngrok / cloudflared), e.g.
//   https://abcd-1234.ngrok-free.app/chama_system
// Leave empty to fall back to the detected local URL (only useful in simulate mode).
define('MPESA_PUBLIC_URL', rtrim((string)mpesa_secret('public_url'), '/'));

// Shared secret appended to the callback URL so random internet traffic cannot
// post fake "paid" results into the system.
define('MPESA_CALLBACK_TOKEN', mpesa_secret('callback_token', 'change-me-in-mpesa-secrets'));

function mpesa_callback_url(): string
{
    $base = MPESA_PUBLIC_URL;
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = $scheme . '://' . $host . BASE_URL;
    }
    return $base . '/mpesa/callback.php?token=' . rawurlencode(MPESA_CALLBACK_TOKEN);
}

// ---------------------------------------------------------------------------
// Are real Daraja credentials present? If not, the system runs in SIMULATE mode
// (a fake STK prompt you can complete from the screen) so demos work with no
// Safaricom account and no money.
// ---------------------------------------------------------------------------
function mpesa_is_configured(): bool
{
    return MPESA_CONSUMER_KEY !== '' && MPESA_CONSUMER_SECRET !== ''
        && MPESA_SHORTCODE !== '' && MPESA_PASSKEY !== '';
}

function mpesa_is_simulation(): bool
{
    return defined('MPESA_SIMULATE') ? (bool)MPESA_SIMULATE : !mpesa_is_configured();
}

// Folder used to cache the OAuth token (must be writable by the web server).
function mpesa_cache_dir(): string
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}
