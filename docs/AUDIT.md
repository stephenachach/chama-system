# Product & Technical Audit — Chama System

Date: 19 Oct 2026 · Scope: full codebase review against the product brief.
Status legend: **FIXED** = implemented and verified in this release (v7) · **OPEN** = planned, not yet done.

---

## P0 — Must be safe before any real money moves

| # | Finding | Resolution |
|---|---------|------------|
| P0-1 | Group dues/loan repayments could be collected on the **platform's** shortcode (commingling risk — group money must never settle to the platform). | **FIXED.** Fund routing gate (`includes/mpesa.php`, `includes/mpesa_payments.php`): group collections only proceed when the group has its own Paybill/Till stored (`chamas.pay_shortcode/pay_passkey`) AND ownership was verified by the operator (`pay_verified=1`). Otherwise the flow refuses STK and falls back to manual recording ("payment setup required"). Subscription payments always use the platform route only. |
| P0-2 | Callbacks did not verify amount, and a paid row could be replayed with a duplicate receipt. | **FIXED.** `mpesa/callback.php`: token required (default token rejected in production → HTTP 503), amount matched to the pending row within KSh 0.01, receipt-number duplicate check (idempotent), route-violation check (`shortcode_used` must equal the group route for group funds). No payment is marked paid without a valid signed-in callback; simulated completion only works in demo mode. |
| P0-3 | Demo/simulation mode was not visibly distinct from live. | **FIXED.** `mpesa_mode()` (simulation / sandbox / production) drives a full-width banner on every authenticated page ("DEMO MODE — no real money moves") and gates the simulate-completion buttons server-side (403 outside simulation). |
| P0-4 | Plan limits were display-only. | **FIXED.** Plans are DB-driven and editable (Superadmin → Billing → Plans). Member caps enforced **server-side** at member create, edit-to-active, and registration approval (`plan_member_slot_available()`). Annual billing = `annual_months_pay` (default 10) months for 12, verified end-to-end. |
| P0-5 | Expiry locked groups out entirely (records held hostage). | **FIXED.** `suspended + nonpayment` → **read-only**: all roles can log in, view everything, download statements and export CSV (`reports/export.php`); writes are blocked except renewing, payment status, password and logout. Manual suspensions keep the stricter lock. |
| P0-6 | SMS effectively unlimited/undisclosed. | **FIXED.** Prepaid per-group SMS credit (`chamas.sms_balance`): live sends deduct `ceil(length/160)` units atomically; zero credit = send refused and logged. Operator tops up in Billing → Pilots. UI never promises unlimited SMS. |

## P0 security floor (required before enabling real users)

| # | Finding | Resolution |
|---|---------|------------|
| S-1 | No CSRF protection on state-changing forms. | **FIXED.** Token per session, verified centrally in `config/auth.php` for every authenticated POST (419 on failure); public/machine endpoints (login, register, start, callback, cron) opt out explicitly. 33 forms carry the token. |
| S-2 | Destructive/sensitive actions were GET links (delete member/contribution/loan/welfare, mark-paid, promote user, approve registration/loan). | **FIXED.** All now POST + CSRF + confirm; the scripts refuse GET. |
| S-3 | No login throttling; no session regeneration on login. | **FIXED.** 6 failures per 10 minutes per email+IP (`login_attempts`) blocks further tries; `session_regenerate_id(true)` on success; counters cleared on success. |
| S-4 | `config/sms-secrets.php` not ignored by git. | **FIXED** (`.gitignore`). |
| S-5 | Default callback token / blank DB credentials usable in production. | **FIXED** in code (default token rejected when `environment=production`); operational steps in `docs/PRODUCTION_SETUP.md`. |

## P1 — Product/UX

1. **FIXED (v10).** Welfare self-service journey: members submit requests (`welfare/apply.php`) and track status; sensitive case details committee-only; configurable payout threshold — payouts at/above it need a second approval by a *different* officer (four-eyes) before Mark Paid unlocks.
2. **OPEN.** Public site: split "Start a group" vs "Join a group" paths clearly, FAQs, product preview, how-it-works, support contact (privacy/security summary page shipped in v7).
3. **OPEN.** Remove remaining "Live update"-style sample bars/figures anywhere they persist.
4. **FIXED (v8).** Committee dashboard rework: this month's contributions vs target, decision queue (welfare/loans/sign-ups/payments awaiting confirmation), follow-up list with call buttons, three primary actions.
5. **FIXED (v9).** Member home screen: dues + due date, part/full/unpaid status, loan status, welfare status, statement, Pay with M-Pesa.
6. **FIXED (v8).** "Welfare Paid" counts only status=paid.
7. **OPEN.** Shorter, phone-friendly member registration; email optional if password reset stays secure.
8. **PARTIAL (v10).** Visual polish: light readable app background, deep-blue navbar + page-head bands, accent decision tiles, coloured totals, one restrained palette on app pages. Remaining: dense tables → mobile card layouts across list pages; registration form shortening (with #7).

### Post-v7 defect found and fixed in v10

The v7 automated POST-conversion of destructive actions updated the UI (forms) but **not** the eight target scripts (deletes, mark-paid, promote, approvals) — they kept reading `$_GET['id']`, so those buttons did nothing and GET-deletes still worked. All eight scripts are now POST-gated with CSRF, re-tested (GET delete refused and row survives; POST delete works).

### Honesty correction (Batch E)
Statements are a **printable one-page HTML view** (browser "Save as PDF" works), not server-generated PDFs. All "PDF" wording in buttons/labels/copy has been corrected accordingly. True server-side PDF export is listed below.

## P2 — Later
- Server-side PDF statement generation (bundled library) instead of print-to-PDF.

1. Kiswahili language plan (string externalisation first).
2. Versioned migrations (currently schema changes auto-apply on request via `migrate_funds_schema()` in `config/db.php` — acceptable for a single non-technical operator, but flagged as a risk; snapshot the DB before upgrades).
3. Assisted-setup/data-import fee as a waivable line item in billing.
4. Enterprise quoting workflow in Billing.

## Explicit caveats

- No regulatory, payments-licensing or ODPC compliance claims are made anywhere; see `docs/PRIVACY.md` — engage a Kenyan-qualified professional.
- Pricing is treated as a launch **hypothesis**: every number is editable in Superadmin → Billing → Plans; no pricing is hard-coded in logic.
- M-Pesa live group collections remain **off** until an operator marks a group route verified — by design.

## Batch F - Email & WhatsApp notifications, human landing copy, phone-fit finish (v14)
**What changed**
- New multi-channel notifier `includes/notifications.php`: email driver (Brevo-style API or PHP mail(), falls back to honest *simulated* logs when unconfigured) plus SMS through the existing pipeline. Every attempt lands in `sms_logs` tagged by `channel` (new column, migrated in db.php). WhatsApp = native `wa.me` click-to-send buttons pre-filled with the reminder text (no third-party dependency).
- Payment receipts (`sms_payment_receipt`) now reach members on email automatically when an address exists, alongside SMS. Monthly dues reminders (cron/reminders.php) do the same. The committee dashboard's "needs follow-up" list gained per-member **Call + WhatsApp** quick actions.
- SMS log screen shows a Channel badge (SMS/EMAIL/WHATSAPP) so nothing is hidden.
- Landing page rewritten in a warm, plain Kenyan voice: shorter hero ("Run your chama without the WhatsApp chaos"), trimmed feature blurbs and FAQs, most em-dashes removed, honest CTA ("The first N days are free. No card, no contract.").
- Remaining desktop tables converted to mobile cards: `users/approvals.php` (registrations + loans) and `users/index.php`, with tel: links and 44px action targets. Public pages got small-screen spacing, centered footer, accordion and navbar touch fixes.

**Honesty notes**
- Emails are only truly sent once `config/email-secrets.php` is created (template provided, git-ignored). Until then every email is logged as *Simulated* exactly like SMS does today. Nothing pretends otherwise.
- WhatsApp automation via provider APIs is deliberately NOT built yet: it requires paid approved templates and business verification. Tap-to-send works immediately at zero cost and keeps a human in the loop, which suits welfare follow-ups.

**Not tested / unchanged**
- Real Brevo/SMTP delivery (no keys in sandbox). Live Daraja STK remains untested end-to-end. Loan overpayment guard and full IDOR sweep still outstanding as previously noted.

## Hardening mini-pass (pre-v15)
- Removed tracked junk file `hdr2.txt`.
- Added `.htaccess` guards: root blocks direct hits on `database.sql`, logs/backups; directory listing disabled. Internal folders (`config/`, `includes/`, `cron/`, `storage/`, `docs/`, `audit/`, `account/`) now refuse all HTTP access so secrets, helpers and scripts cannot be downloaded even by URL guessing. Works out of the box on XAMPP/Apache with default `AllowOverride All`.


## Batch G - Loan limits and two-officer approvals (v15)
**What changed**
- Groups can now configure loan policy in Settings: a savings multiplier (default 3x), an optional hard ceiling in KSh, and a threshold above which loans must be approved by two different officers. Caps are enforced server-side at application AND approval; officers can record an audited override when committees decide exceptions.
- Loans table gained approval tracking columns (approved_by, needs_second, second_ok/by/at). The approve screen shows the live group cap, warns clearly on violations, and walks officers through the four-eyes flow with honest button labels ("Record first approval", "Give second approval & approve"). Existing welfare payout dual-approval stays unchanged.
- All limits are per-chama defaults-friendly: multiplier 0 switches caps off, threshold 0 keeps single approval, so existing groups see no behaviour change until they opt in.

**Not tested / honest notes**
- Live M-Pesa STK and real SMS/email delivery remain sandbox-untested. Two-officer UI happy paths were exercised manually plus unit checks on the cap maths; a formal cross-role permission sweep is still outstanding as previously noted.
