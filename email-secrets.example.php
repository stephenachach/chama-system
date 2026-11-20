<?php
/**
 * Copy this file to config/email-secrets.php (which is git-ignored) and fill it in.
 * Without it, emails are logged as "simulated" - nothing fails, nothing pretends.
 *
 * driver 'api'  : transactional API (Brevo's free tier works well from Kenya).
 *                  Create an account at brevo.com, get an API key (v3), sender = your verified address.
 * driver 'mail' : PHP mail(). Needs SMTP/sendmail configured on the server
 *                  (works out of the box on most Linux hosting; NOT on default XAMPP).
 */
return [
    'driver'      => 'none',                       // 'none' | 'mail' | 'api'
    'from'        => 'noreply@yourdomain.co.ke',   // must be a real, verified sender
    'sender_name' => 'Your Chama System',
    'api_key'     => '',                           // Brevo api-key value
    'api_url'     => 'https://api.brevo.com/v3/smtp/email',
];
