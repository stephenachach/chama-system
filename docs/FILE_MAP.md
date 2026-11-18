# File Map — what everything is (and why similar names are NOT duplicates)

One installation = many separate groups (tenants). Folders are areas; each file does one job.

## Public (no login)
| File | Purpose |
|---|---|
| `index.php` | Landing page: Start-a-group vs Join-a-group, features, pricing, FAQ, support contacts |
| `start.php` | **Groups** create their chama + 30-day pilot (one form) |
| `register.php` | **Members** join an existing group (committee approves) |
| `login.php` / `logout.php` | Authentication (throttled, session regenerated) |
| `privacy.php` | Public privacy & security summary |

## Core config & includes
| File | Purpose |
|---|---|
| `config/db-credentials.php` | DB host/name/user/password — edit per server |
| `config/db.php` | Connection + automatic idempotent schema migrations |
| `config/app.php` | Constants/helpers (no DB) |
| `config/auth.php` | Sessions, roles, tenant scoping, CSRF verification |
| `config/mpesa.php` | Daraja modes (simulation/sandbox/production), OAuth, STK, fund-routing gate |
| `config/*.example.php` | Templates for `mpesa-secrets.php` / `sms-secrets.php` (real ones are git-ignored) |
| `includes/*` | Shared logic: header/footer, billing, plans, SMS, PDF, audit, M-Pesa payments |

## App areas (login required)
| Folder | Who | What |
|---|---|---|
| `dashboard.php` | all | Committee dashboard / member home screen / platform dashboard |
| `settings.php` | superadmin | Public support contacts |
| `members/` | committee | Member records; `statement.php` = per-member PDF |
| `contributions/` | committee + members | `create.php` manual record · `pay.php` member M-Pesa STK · `delete.php` |
| `loans/` | committee + members | `create.php`/`approve.php` decisions · `repayment.php` committee records a cash/bank repayment · `repay.php` member pays by M-Pesa STK |
| `welfare/` | committee + members | `create.php` committee logs a case · `apply.php` member self-service + tracking · `approve.php` decision page · `second_confirm.php` four-eyes for large payouts · `mark_paid.php` |
| `users/` | committee | Logins/roles: `create.php` add officer · `promote.php` · `approvals.php` queue · `approve_registration.php` / `approve_loan.php` (POST actions) |
| `reports/` | committee | Dashboards + `export.php` CSV (works in read-only mode) |
| `sms/` | committee | Send SMS (prepaid credit) + logs |
| `audit/` | committee | Audit trail |
| `account/` | managers | `subscription.php` renew · `payment_route.php` group Paybill/Till · `password.php` |
| `chamas/` | superadmin | Manage groups |
| `billing/` | superadmin | Platform billing: `index.php` hub · `pay.php` bill a group · `mark_paid.php` record cash subscription · `extend.php` grant days · `plans.php` edit pricing · `pilots.php` pilot pipeline, route verification, SMS top-up |
| `mpesa/` | — | `callback.php` Daraja webhook (token-protected) · `status.php` poll checkout · `logs.php` API logs |
| `cron/` | server | `reminders.php` daily dues SMS · `subscriptions.php` expiry → read-only |
| `database.sql` | — | Full fresh-install schema + seed |
| `docs/` | — | Audit, production setup, handover agreement, privacy notes, this map |

## Similar names, different jobs (deliberate, not duplicates)
- `billing/mark_paid.php` = superadmin records a **subscription** cash payment; `welfare/mark_paid.php` = committee marks a **welfare payout** paid.
- `loans/repayment.php` = committee **records** a repayment (cash/bank); `loans/repay.php` = member **pays by M-Pesa**.
- `welfare/create.php` = committee logs a case on behalf of a member; `welfare/apply.php` = member submits their own request.
- `contributions/create.php` = committee records any contribution; `contributions/pay.php` = member self-pays by M-Pesa.
- `users/create.php` = committee creates an officer login; `register.php` = public member sign-up (needs approval); `start.php` = public group creation.
- `users/approve_loan.php` = POST action from the approvals queue; `loans/approve.php` = loan decision page.
