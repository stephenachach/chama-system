<?php
/**
 * SMS provider credentials (Phase 3).
 *
 * Copy this file to  config/sms-secrets.php  and fill in your details.
 * Until you do, the app runs in SIMULATION mode: messages are logged
 * (SMS > Log) but nothing is really sent and nothing is billed.
 *
 * Getting real SMS for the lowest possible cost:
 *   1. Create a free account at https://africastalking.com (sandbox needs no card)
 *   2. Dashboard > SMS > create a sender ID (or use the sandbox default)
 *   3. Dashboard > API Keys > copy your key
 *   4. Paste the three values below. Sandbox messages only reach numbers
 *      you have added to your sandbox account; live SMS in Kenya costs a
 *      few shillings per message and you pay per top-up, no contract.
 */
return [
    // 'simulation'  = log only, free, safe for demos and testing
    // 'africastalking' = real SMS through the Africa's Talking API
    'provider'  => 'simulation',

    // Your Africa's Talking username (sandbox username ends in 'sandbox',
    // e.g. 'sandbox_ace0f4...').
    'username'  => '',

    // Dashboard > API Keys. Keep this file out of Git like mpesa-secrets.php.
    'api_key'   => '',

    // Sender ID shown on the phone, e.g. your chama name (max 11 chars).
    // Leave empty in the sandbox.
    'sender_id' => '',
];
