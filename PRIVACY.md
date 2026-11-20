# Privacy & Data Protection Notes (operator guidance)

**This is not legal advice and makes no compliance claims.** Welfare records can reveal health, bereavement or hardship and are likely **sensitive personal data** under Kenya's Data Protection Act, 2019. Engage a Kenyan-qualified privacy professional (and, where relevant, payments/licensing counsel) before processing live data at scale, and before any ODPC filing decision.

## Roles

- **Each chama/group is the data controller** of its members' data: it decides why and how member, contribution, loan and welfare data is processed.
- **The platform operator is a processor** hosting and operating the software on the group's behalf. A written processor agreement per group should cover: purpose limitation, confidentiality, security measures, sub-processors (Safaricom/Daraja, SMS gateway, hosting provider), breach notification timelines, assistance with data-subject requests, and deletion/return on exit.

## What the system holds

| Category | Examples | Sensitivity |
|---|---|---|
| Member identity | name, phone, member no., optional email, join date | personal |
| Financial | contributions, loans, repayments, balances, statements | personal + financial |
| Welfare | request reason, notes, amounts, decisions | **sensitive** (may reveal health/hardship) |
| Authentication | email, bcrypt password hash, login attempts | personal |
| Payments | M-Pesa checkout/receipt refs, phone numbers, amounts | personal + financial |
| Platform billing | subscriptions, invoices, plan usage | business |
| Audit | who did what, when (immutable-ish trail) | operational |

## Built-in protections (v7)

- Tenant isolation: every query is scoped by `chama_id`; IDOR guards on record fetches.
- Role permissions: member / treasurer / admin / superadmin; welfare case detail restricted to committee roles.
- Passwords: bcrypt; login throttling; session regeneration; CSRF on all state-changing forms; destructive actions POST-only.
- Read-only on expiry: groups can always view and export their records — data is never held hostage.
- Secrets (M-Pesa, SMS) live outside git; callback token required and rejected if default in production.

## Retention & deletion (set a policy, then follow it)

Suggested baseline to confirm with counsel: active records retained while the group is a customer; after contract end, provide export (CSV/statement PDFs), then delete production data after a fixed window (e.g. 90 days) except what financial/tax law requires the group to keep — retention duties sit primarily with the **group as controller**. Audit logs: retain for the same period as the records they reference. Backup purge schedule must match.

## Data subject requests

The group (controller) handles member requests (access, correction, deletion, objection). The platform must be able to: export a member's data (already possible via statements/CSV), correct records, and delete/anonymise a member record. Document the request path and response times in the processor agreement.

## Breach response

Detect (monitored logs) → contain (rotate secrets/tokens) → assess → notify the affected groups promptly so they can meet their controller obligations → record. Keep an incident template in `storage/`.

## Cross-border & hosting

Note where the server and backups physically sit. If hosting outside Kenya, the processor agreement and any required ODPC steps must address cross-border transfer. Prefer a Kenya-region or reputable provider with clear data-residency terms.

## ODPC

Whether registration as a data controller/processor is required, and any data-protection impact assessment for welfare processing, must be decided with a Kenyan privacy professional. Nothing in this codebase or these docs asserts ODPC compliance.

## Public-facing wording

`privacy.php` (public page) states plainly what data is collected, who controls it, that welfare detail is restricted to the member's committee, that groups can export their data even if the subscription lapses, and how to contact the operator. Keep it truthful and in step with this file.
