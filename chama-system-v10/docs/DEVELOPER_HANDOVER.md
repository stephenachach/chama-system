# Developer Handover & Payment Agreement (template)

This document is the working template for the client–developer relationship. **Nothing about this engagement should be verbal** — every milestone, acceptance, payment, access handover and change request should be confirmed in writing (email is fine).

## 1. Parties, ownership & IP

- The **client owns** the delivered source code, database schema, documentation and brand assets on payment of the associated milestone ("Work for hire").
- The developer retains the right to reuse general knowledge and generic, non-client-specific techniques, but not client-specific code, data, branding or the group/business data itself.
- Third-party components: Bootstrap, FontAwesome, FPDF/TCPDF-class libraries and Daraja/Africa's Talking SDK patterns are used under their own licences; no proprietary licensed component is embedded without disclosure.

## 2. Milestone-based payment schedule

Payments are released **only on written acceptance** of the milestone deliverables. Each acceptance requires: working build (zip or repo tag), changelog, test report, and any setup docs listed for that milestone.

| Milestone | Deliverable | Payment trigger |
|-----------|-------------|-----------------|
| M1 — P0 fund safety & security floor (v7) | Tenant-specific M-Pesa routing, callback verification & idempotency, demo/live separation, DB-driven plans + server-side limits, read-only expiry + CSV export, prepaid SMS, CSRF, POST-only destructive actions, login throttling, session regeneration, audit + production setup + privacy docs | Client (or client's tester) runs the acceptance checklist below and signs off |
| M2 — Committee & member UX | Dashboard rework (contributions this month, pending welfare, approvals, payments awaiting confirmation, follow-ups), member home screen, mobile card layouts, single accessible palette, plain-language labels | Acceptance checklist |
| M3 — Welfare journey & public site | Member welfare submit → status timeline, payout-threshold extra approval, restricted sensitive case info, public site Start-a-group / Join-a-group split, FAQs, privacy & security pages, product preview | Acceptance checklist |
| M4 — Production launch | Deployment, HTTPS, backups + tested restore, live Daraja cutover with first verified group route, monitoring | First live subscription + first live group collection both settle in the correct accounts |

Suggested split (adjustable): 30% M1 · 25% M2 · 25% M3 · 20% M4. Invoicing in KES; payment via bank or the platform's own billing once live.

## 3. Access handover (per milestone)

- Source: client's GitHub organisation/account receives the tag/branch; developer access continues only as needed and is removed on final handover.
- Hosting/DB/Daraja/SMS dashboards: client holds master credentials; developer receives scoped access only where required, revoked at milestone close.

## 4. Defect-fix period

- **30 days** from acceptance of each milestone (60 days recommended for M4 go-live): developer fixes any defect that breaks an accepted deliverable at no charge.
- "Defect" = behaviour deviating from the brief, this docs set, or the acceptance checklist. New features are change requests.

## 5. Maintenance agreement (separate, written)

Covering at minimum: hosting & backup operations, security patches, PHP/MySQL upgrades, Daraja/SMS provider API changes, monitored logs, incident response times, small change budget (e.g. X hours/month), and pricing. It starts only after M4 acceptance and is renewed in writing.

## 6. Acceptance checklist for M1 (run before paying)

1. Demo mode: banner visible; simulate-completion works; no live API calls.
2. Group without a verified route: member "Pay via M-Pesa" is refused with a clear message; manual recording works.
3. Group with verified route (sandbox): STK prompt appears; callback with wrong amount → payment failed, no contribution; correct callback → contribution recorded once; replayed receipt → duplicate rejected.
4. Subscription payment uses the platform shortcode; group collection uses the group shortcode (`shortcode_used` in DB/logs).
5. Superadmin → Billing → Plans: change a price/cap; member cap blocks creation server-side; annual charge = 10 months for 12.
6. Expire a test group (cron or manual): all roles can log in read-only, view data, export CSV; writes blocked except renewal; renewal restores full access.
7. Security: POST without CSRF token → 419; delete via URL (GET) → refused; 7th failed login within 10 minutes → blocked; session cookie regenerated on login.
8. Zero PHP warnings in the server log during the above.

## 7. Change requests

Written description, agreed effort and price **before** work starts. Urgent production issues follow the maintenance agreement's incident process.
