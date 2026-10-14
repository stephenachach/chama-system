<?php
/**
 * M-Pesa credentials template.
 *
 *   1. Copy this file to:  config/mpesa-secrets.php
 *   2. Fill in the values from https://developer.safaricom.co.ke
 *   3. Never commit mpesa-secrets.php to git (it is already in .gitignore).
 *
 * SANDBOX (free, no real money):
 *   - Register at developer.safaricom.co.ke, create a "Sandbox" app,
 *     copy its Consumer Key and Consumer Secret below.
 *   - Shortcode stays 174379 and the passkey stays the default in config/mpesa.php.
 *   - Test phone number: 254708374149
 *
 * PRODUCTION (real money):
 *   - Go live on Daraja, then create a "Live" app for its Consumer Key/Secret.
 *   - Shortcode = your Paybill or Till number.
 *   - Passkey = the one Daraja shows next to your short code.
 *   - Also change 'environment' below to 'production'.
 */

return [
    // 'sandbox' or 'production'
    'environment'      => 'sandbox',

    'consumer_key'     => '',
    'consumer_secret'  => '',

    // Your Paybill / Till number (sandbox: 174379)
    'shortcode'        => '',

    // Daraja passkey for your short code (sandbox default is built in)
    'passkey'          => '',

    // 'CustomerPayBillOnline' for Paybill, 'CustomerBuyGoodsOnline' for Till
    'transaction_type' => 'CustomerPayBillOnline',

    // If you use a Paybill, the account number shown to the payer (optional)
    'account_reference' => 'SUBSCRIPTION',

    // Public HTTPS address of this installation, WITHOUT a trailing slash.
    // Local XAMPP + tunnel, e.g.:  https://abcd-1234.ngrok-free.app/chama_system
    // On real hosting, e.g.:        https://yourdomain.co.ke
    'public_url'       => '',

    // Any long random string. It protects the callback endpoint from forged
    // "payment successful" requests. Change it from the default.
    'callback_token'   => 'change-me-to-a-long-random-string',
];
