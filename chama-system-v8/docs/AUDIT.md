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

## P1 — Product/UX (planned, **OPEN**)

1. Welfare self-service journey for members (submit → status timeline → payout), with sensitive case details restricted to committee; configurable extra approval above a payout threshold.
2. Public site: split "Start a group" vs "Join a group" paths clearly, FAQs, privacy/security summary, product preview, how-it-works, support contact.
3. Remove remaining "Live update"-style sample bars/figures anywhere they persist.
4. Committee dashboard rework: lead with this month's contributions, pending welfare, approvals queue, payments awaiting confirmation, members needing follow-up; obvious primary actions (Record payment / Review welfare / Invite member).
5. Member home screen: dues + due date, payment status, welfare status, loan status, statement link.
6. "Welfare Paid" stat must count only actually-paid cases (verify current logic while doing #1).
7. Shorter, phone-friendly member registration; email optional if password reset stays secure (phone-based reset or committee reset).
8. Dense tables → mobile card layouts; tappable button sizes; one restrained high-contrast palette; plain-language labels; KES always visible.

## P2 — Later

1. Kiswahili language plan (string externalisation first).
2. Versioned migrations (currently schema changes auto-apply on request via `migrate_funds_schema()` in `config/db.php` — acceptable for a single non-technical operator, but flagged as a risk; snapshot the DB before upgrades).
3. Assisted-setup/data-import fee as a waivable line item in billing.
4. Enterprise quoting workflow in Billing.

## Explicit caveats

- No regulatory, payments-licensing or ODPC compliance claims are made anywhere; see `docs/PRIVACY.md` — engage a Kenyan-qualified professional.
- Pricing is treated as a launch **hypothesis**: every number is editable in Superadmin → Billing → Plans; no pricing is hard-coded in logic.
- M-Pesa live group collections remain **off** until an operator marks a group route verified — by design.
