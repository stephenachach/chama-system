# Chama Management System (Multi-Tenant + M-Pesa Billing)

Manage and monitor the records of financial groups (chamas): members, contributions,
loans, welfare and reports — with **multi-chama support** (one installation hosts many
separate groups) and **M-Pesa subscription billing** (each group pays a monthly fee by
STK Push; plans expire and non-payment suspends access automatically).

## Tech stack

- PHP 8+ (procedural + helpers, no framework)
- MySQL / MariaDB (PDO, prepared statements everywhere)
- Bootstrap 5 (CDN)
- Safaricom Daraja API (STK Push) — works in **demo mode** with no credentials at all

## Roles

| Role | Scope | Can do |
|---|---|---|
| `superadmin` | Whole platform | Create/suspend chamas, set plans, fees and expiry dates, bill by M-Pesa, record cash payments, grant free time, see platform revenue and M-Pesa logs. No access inside a single chama's data. |
| `admin` | One chama | Everything inside that group: members, contributions, loans, welfare, reports, users — plus its own subscription page. |
| `treasurer` | One chama | Finance operations: contributions, loans, welfare, approvals, reports, subscription page. |
| `member` | Own records | View own stats, apply for loans, register. |

## Fresh install

1. Create an empty database:
   ```sql
   CREATE DATABASE IF NOT EXISTS chama_system_db CHARACTER SET utf8mb4;
   ```
2. Import the schema (phpMyAdmin or CLI):
   ```bash
   mysql -u root chama_system_db < database.sql
   ```
3. Put the project in your web root (XAMPP: `htdocs/chama_system`) and adjust
   credentials in `config/db.php` if needed (defaults: `root` / empty password).
4. Open the app in your browser.

**Platform login (seeded):** `superadmin@chama.local` / `Chama@2026`
→ change it immediately via **My Account → Change Password**, and update
`SUPERADMIN_INITIAL_PASSWORD` in `config/app.php`.

## Upgrading an existing installation

No manual steps and no re-import. The first page load after updating applies whatever
is missing:

**Phase 1 (multi-tenancy)**
1. Creates the `chamas` table.
2. Adds `chama_id` (indexed) to `users`, `members`, `contributions`, `loans`, `welfare_payouts`.
3. Extends `users.role` with `superadmin`.
4. Seeds a "Default Chama" and moves **all existing data** into it — nothing is lost.
5. Seeds the platform operator account.

**Phase 2 (billing)**
6. Adds `billing_phone`, `plan_expires_at` and `suspension_reason` to `chamas`.
7. Creates `subscription_payments` and `mpesa_logs`.

Existing chamas get `plan_expires_at = NULL`, which means **"never billed, never
suspended"** — so upgrading can never lock a customer out by accident. Set an expiry
date on a group when you start charging it (the Billing page reminds you).

## Operating the platform (as superadmin)

1. **Create a chama** (Chamas → New Chama): name, plan, monthly fee, free-trial days,
   billing phone, and its first admin. A paid plan starts its clock automatically
   (today + trial days); a fee of `0` means free forever.
2. **Share the registration link**: `register.php?chama=<slug>` — members pick the
   group automatically from the link.
3. **Billing** (nav → Billing): see every group's fee, expiry date, last payment and
   lifetime value; send an M-Pesa STK prompt; record cash/bank payments; grant free
   days; and watch what was collected this month plus your recurring monthly total.
4. **Suspend a group manually** to block all its logins (fraud, disputes). This is
   separate from non-payment suspension — see below.

## Subscription lifecycle

```
Plan created ──► active (days left shown everywhere)
                     │
     expiry - 7 days │  warning banner: "Expires in N days"
                     ▼
                  expired ──► grace period (default 3 days, still working)
                     │
                     ▼
            AUTO-SUSPENDED (reason: nonpayment)
                     │
      admin/treasurer can still log in ──► restricted to the Subscription page
                     │
                 pays by M-Pesa
                     ▼
        plan extended + access restored instantly
```

- Grace period: `SUBSCRIPTION_GRACE_DAYS` in `config/app.php` (default 3).
- Reminder window: `SUBSCRIPTION_REMINDER_DAYS` (default 7).
- **Non-payment suspension** keeps the billing page reachable so the group can pay
  itself back in. **Manual suspension** blocks every login.
- Members of a suspended group are always fully blocked.
- Suspension runs automatically on page loads (at most every 5 minutes) and can also
  be forced: `php cron/subscriptions.php` or
  `cron/subscriptions.php?token=<MPESA_CALLBACK_TOKEN>`.
- A payment extends from the current expiry when the plan is still valid (pre-paying
  stacks months) or from today when it has already lapsed.

## Member payments: dues & loan repayments by M-Pesa (Phase 2b)

Members (and their treasurer/admin on their behalf) pay straight from a phone:

1. A member opens **Pay Dues** (or the "Pay my dues by M-Pesa" button on their
   dashboard), confirms the amount (pre-filled with the group's *member dues*)
   and their phone number, and taps **Send STK Push**.
2. Safaricom prompts for their PIN. On success the callback creates the
   **contribution record automatically** - type `mpesa`, reference = the M-Pesa
   receipt number. No manual data entry, no disputes about who paid.
3. For loans: **Loans -> Pay M-Pesa** on any approved loan with a balance. The
   repayment row is created and the balance reduced in one transaction; when the
   balance reaches zero the loan flips to `paid`. Amounts above the balance are
   clamped so nobody can overpay.
4. Treasurers see live "M-Pesa requests awaiting confirmation" on the
   Contributions page and an `M-Pesa` badge on every auto-recorded contribution.

Safety: a member can only pay for themselves; a receipt can only be used once
platform-wide; every payment is tenant-scoped; cancelled prompts create no
records; and the same completion code runs for the real callback and the demo
"simulate customer paid" button.

Set the suggested dues per group in **Chamas -> Edit -> Member dues**.

## M-Pesa (Daraja) STK Push

### Demo mode — works out of the box

With no credentials configured the system runs in **demo mode**: the STK request is
simulated (same pending payment record, same states, same expiry logic) and you confirm
it with one click — *"Simulate: customer accepted & paid"*. Use this for demos and for
building everything else. The banner on the Billing page always tells you which mode
you are in.

### Turning on real M-Pesa (sandbox, free)

1. Register at <https://developer.safaricom.co.ke> and create a **Sandbox** app.
2. Copy this file:
   ```
   config/mpesa-secrets.example.php  →  config/mpesa-secrets.php
   ```
3. Fill in `consumer_key` and `consumer_secret`. Leave `shortcode` and `passkey`
   empty — the sandbox values (174379) are built in.
4. Set `callback_token` to any long random string.
5. Test number: **254708374149**. Sandbox STK prompts only succeed on test numbers.
6. Expose your local XAMPP so Safaricom can reach the callback (see below), then put
   that public address in `public_url`, e.g.
   `https://abcd-1234.ngrok-free.app/chama_system`.

### Going live (real money)

1. Complete Daraja go-live, then create a **Live** app for real Consumer Key/Secret.
2. In `config/mpesa-secrets.php`: set `environment` to `production`, your Paybill or
   Till as `shortcode`, your Daraja `passkey`, and
   `transaction_type` to `CustomerPayBillOnline` (Paybill) or
   `CustomerBuyGoodsOnline` (Till).
3. Deploy on real hosting with HTTPS and set `public_url` to that address.
4. In the Daraja portal, register the callback URL shown on **Billing → M-Pesa Logs**.
5. Send yourself a KES 1 test bill before charging customers.

### Receiving callbacks while running on XAMPP (local)

Safaricom must POST to a public HTTPS address. Two free options:

**cloudflared (no account needed)**
```powershell
# download cloudflared.exe from https://github.com/cloudflare/cloudflared/releases
.\cloudflared.exe tunnel --url http://localhost:80
# it prints e.g. https://abcd-1234.trycloudflare.com
```

**ngrok (free account)**
```powershell
ngrok http 80
# it prints e.g. https://abcd-1234.ngrok-free.app
```

Put that URL + your project folder into `public_url` in `config/mpesa-secrets.php`.
The full callback URL is always displayed on **Billing → M-Pesa Logs** — copy it from
there. Tunnels change address each restart, so update `public_url` when you restart one.

If a callback is lost, the payment still resolves: while a payment is pending, the
screen polls `mpesa/status.php`, which asks Daraja directly for the result.

### Where the billing code lives

| Path | Purpose |
|---|---|
| `config/mpesa.php` | Endpoints, short code, passkey, callback URL, mode detection |
| `config/mpesa-secrets.php` | **Your credentials (never committed)** |
| `includes/mpesa.php` | OAuth token cache, STK Push, status query, request logging |
| `includes/billing.php` | Expiry maths, extensions, auto-suspension, payment recording |
| `mpesa/callback.php` | Public Daraja webhook (token-protected) |
| `mpesa/status.php` | Authenticated JSON poller for a pending payment |
| `mpesa/logs.php` | Raw request/response trail for debugging |
| `billing/index.php` | Platform billing dashboard |
| `billing/pay.php` | Send an STK prompt to a chama (1–12 months) |
| `billing/mark_paid.php` | Record cash/bank/cheque payment |
| `billing/extend.php` | Grant free days (audited as a `trial` entry) |
| `contributions/pay.php` | Member/treasurer: pay dues by STK (auto-records the contribution) |
| `loans/repay.php` | Member/treasurer: repay a loan by STK (auto-updates the balance) |
| `includes/mpesa_payments.php` | Payment engine for dues & repayments (pending -> paid/failed) |
| `account/subscription.php` | A chama's own plan, expiry, M-Pesa payment and history |
| `cron/subscriptions.php` | Optional scheduled enforcement + report |
| `storage/` | OAuth token cache and maintenance lock (writable, git-ignored) |

## Security model

- Every query for members, contributions, loans, welfare, users and payments is
  filtered by the logged-in user's `chama_id`; every edit/delete first verifies the
  record belongs to that group (no cross-group access by id).
- `billing/*` and `mpesa/logs.php` are superadmin-only; `mpesa/status.php` rejects
  payments belonging to another chama (403).
- The Daraja callback is public but protected by a shared token
  (`MPESA_CALLBACK_TOKEN`) compared with `hash_equals()`; a bad token gets 403.
- Every M-Pesa receipt number is unique in the database, so replaying an old receipt
  cannot buy extra months.
- Amounts and periods are recomputed server-side — the client never sends a price.
- Passwords: `password_hash()` / `password_verify()`. All input: PDO prepared
  statements; all output: `htmlspecialchars`.
- OAuth tokens are cached on disk in `storage/` (outside the web root's data flow) and
  passwords/passkeys are redacted from logs.

## Roadmap

- [x] Phase 1 — Multi-tenancy (per-chama isolation, superadmin platform, auto-upgrade)
- [x] Phase 2 — Subscription billing: plan expiry, M-Pesa STK Push, auto-suspension
- [x] Phase 2b — STK Push for member dues and loan repayments (auto-recorded)
- [ ] Phase 3 — SMS reminders (Africa's Talking), PDF statements, audit log
- [ ] Phase 4 — Sell: KES 5,000–10,000 setup + KES 500–1,500/month per chama
- [ ] Mobile PWA polish
- [ ] Per-chama impersonation for platform support
- [ ] SACCOS mode (shares, dividends, multi-branch) on the same tenant structure
