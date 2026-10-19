# Production Setup Guide (Chama System v7)

Follow in order. Do not enable live M-Pesa until every step is complete and a test collection has settled into the **group's own** Paybill/Till.

## 1. Hosting requirements

- PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring` (recommended), HTTPS on every page.
- MySQL 5.7+/MariaDB 10.4+.
- Cron access. File uploads dir writable (`storage/`).

## 2. Database (never root, never blank)

```sql
CREATE DATABASE chama_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'chama_app'@'localhost' IDENTIFIED BY 'PUT-A-STRONG-RANDOM-PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP ON chama_prod.* TO 'chama_app'@'localhost';
```

Import the schema: `mysql -u chama_app -p chama_prod < database.sql`

Then edit `config/db.php` (host, dbname, user, password). Strong secrets everywhere; do not commit real credentials to git.

## 3. M-Pesa (Daraja)

Create `config/mpesa-secrets.php` (it is git-ignored):

```php
<?php
return [
    'environment'    => 'production',            // 'sandbox' while testing, 'production' for live
    'consumer_key'   => '...live consumer key...',
    'consumer_secret'=> '...live consumer secret...',
    'shortcode'      => 'YOUR PLATFORM PAYBILL', // used ONLY for group subscriptions
    'passkey'        => '...platform passkey...',
    'callback_token' => '...random 40+ chars — generate with: php -r "echo bin2hex(random_bytes(24));"',
];
```

- Daraja STK callback URL: `https://yourdomain/mpesa/callback.php?token=YOUR_CALLBACK_TOKEN`
- In `production` the app **rejects** the default token (HTTP 503) — you must change it.
- **Platform shortcode is for subscription payments only.** Group dues/loan repayments are refused on the platform route (callback marks them `failed: route-violation`).

## 4. Per-group collection routes (the money-safety workflow)

1. Group admin enters their Paybill/Till + passkey: **Account → M-Pesa collection route** (any edit resets verification).
2. Operator confirms ownership (business line confirmation, documents, or a witnessed small test payment) and marks it verified: **Billing → Pilots → Verify**.
3. Until verified, that group's members see "payment setup required" and staff record payments manually. Live STK to a group route only runs when verified.
4. In `sandbox` mode all group collections are allowed and clearly labelled simulated/sandbox.

## 5. SMS (prepaid)

- Create `config/sms-secrets.php` (git-ignored) with your provider credentials (e.g. Africa's Talking).
- Without secrets, SMS stays in simulation (logged, never sent).
- Credit each group: **Billing → Pilots → +SMS**. Live sends deduct units (1 unit = 160 chars/segment); at zero credit sending is refused and logged. Disclose per-message pricing and who pays.

## 6. Cron

```
0 7 * * *  php /path/to/app/cron/reminders.php     >> /path/to/app/storage/cron.log 2>&1
0 6 * * *  php /path/to/app/cron/subscriptions.php >> /path/to/app/storage/cron.log 2>&1
```

`cron/subscriptions.php` handles trial end → grace → `suspended/nonpayment` (read-only, never data loss).

## 7. Backups & monitoring

- Nightly `mysqldump` off-server + monthly **tested restore** onto a scratch DB.
- Monitor the web server error log and `storage/` logs; alert on repeated 4xx on `mpesa/callback.php` (token attacks) and on PHP warnings/fatals.

## 8. Go-live checklist

- [ ] DB creds non-root/strong; secrets files git-ignored and absent from repo history
- [ ] `environment=production`, live keys, **callback token changed**
- [ ] HTTPS enforced everywhere (redirect 80→443, HSTS)
- [ ] First group route verified only after ownership proof; test STK settles in **group** account
- [ ] Subscription STK settles in **platform** account
- [ ] Cron installed; backups running + one restore tested
- [ ] Superadmin password changed; default `Chama@2026` never used in prod
- [ ] `docs/PRIVACY.md` reviewed with a Kenyan privacy professional (welfare records are sensitive personal data)

## Note on schema migrations

Small schema upgrades currently auto-apply on first request (`migrate_funds_schema()` in `config/db.php`). This is intentional for a non-technical operator, but: **take a DB snapshot before deploying any new version.** A move to versioned migrations is on the P2 roadmap.
