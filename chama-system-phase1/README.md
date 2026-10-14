# Chama Management System (Multi-Tenant)

Manage and monitor the records of financial groups (chamas): members, contributions,
loans, welfare and reports — now with **multi-chama support** so one installation
can host many separate groups.

## Tech stack

- PHP 8+ (procedural + helpers, no framework)
- MySQL / MariaDB (PDO, prepared statements everywhere)
- Bootstrap 5 (CDN)

## Roles

| Role | Scope | Can do |
|---|---|---|
| `superadmin` | Whole platform | Create/suspend chamas, set plans & fees, see platform stats. Has no access inside a single chama's data. |
| `admin` | One chama | Everything inside that group: members, contributions, loans, welfare, reports, user management. |
| `treasurer` | One chama | Finance operations: contributions, loans, welfare, approvals, reports. |
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

## Upgrading an older (single-chama) installation

No manual steps. The first page load after the update automatically:

1. Creates the `chamas` table.
2. Adds `chama_id` (indexed) to `users`, `members`, `contributions`, `loans`, `welfare_payouts`.
3. Extends `users.role` with `superadmin`.
4. Seeds a "Default Chama" and moves **all existing data** into it — nothing is lost.
5. Seeds the platform operator account (same initial credentials as above).

All your previous logins keep working; their data now lives under the Default Chama.

## Operating the platform (as superadmin)

1. **Create a chama** (Chamas → New Chama): set name, plan and monthly fee, and
   create its first admin.
2. **Share the registration link** with the group: `register.php?chama=<slug>` —
   members pick the group automatically from the link.
3. **Suspend a group** to block its logins (e.g. non-payment); activate again anytime.
4. Track revenue on the platform dashboard: monthly revenue = sum of fees of
   active chamas.

## Security model

- Every query for members, contributions, loans, welfare and users is filtered
  by the logged-in user's `chama_id`, and every edit/delete first verifies the
  record belongs to that group (no cross-group access by id).
- Passwords: `password_hash()` / `password_verify()`.
- All user input: PDO prepared statements + `htmlspecialchars` on output.
- Suspended chamas are blocked at login.

## Roadmap

- [ ] M-Pesa (Daraja) STK Push for contributions & repayments
- [ ] SMS reminders (Africa's Talking)
- [ ] Member statements (PDF) + audit log
- [ ] Mobile PWA polish
- [ ] Per-chama impersonation for platform support
