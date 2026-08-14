# Ops Portal V2 — Release Candidate V1.0

## Release identity

- Branch: `release-candidate/v1.0`
- Phase 8.7 merged baseline: `cab3213544c279c83461303075b09a83879974e7`
- Baseline GitHub Actions: run `31751480848`, green
- RC checkpoints: `5207867`, `a729ae8`, `be20459`, `b04ee01`, `c822a26`, `1b75155`, `075a136`, and `02cbacd`
- Scope boundary: Release Candidate stabilization only. Phase 8.8 and Phase 9 are not included.

## Delivered behavior

| Area | Release Candidate behavior |
| --- | --- |
| Visit assignment | Exactly one valid assignee is automatically made lead. Two or more assignees still require an explicit lead. Organization, active-membership, field-access, and schedule-conflict checks remain authoritative. |
| Ticket callbacks | Completed and canceled Service Tickets may be reopened with a required reason and idempotency token. Prior Visits, Closeouts, reviews, Billing Handoffs, invoices, payments, and audit history remain unchanged. Reopen creates a new callback Visit and never edits prior financial history. |
| Invoice line removal | Draft and Ready-for-Review lines may be removed with a reason and exact confirmation. Issued and void invoices remain immutable. Source Visit, Closeout, Review, Catalog, time, and proposal records are preserved. |
| Direct invoices | Authorized Billing users may create an Invoice without a Service Ticket or Billing Handoff. Customer and Service Location remain required and organization scoped. Existing handoff-generated invoices remain compatible. |
| Approved Visit labor | Eligible approved, nonarchived, unbilled Visit labor can be attached to an editable Invoice. Pricing uses the Phase 8.7 labor resolver/calculator and immutable Catalog snapshots. Database-backed duplicate-billing protection remains authoritative. |
| Billing workspace | Billing Handoffs and Invoices are presented in one `Billing / Invoices` workspace. The legacy handoff URL redirects to `workspace=ready_to_invoice`; the Billing Handoff remains the durable internal workflow record. |
| Mobile Closeout | Assigned and En Route prioritize execution and do not show a Closeout footer. On Site and Returned for Correction show a compact footer (validated at no more than 80 px) that opens the existing accessible review dialog. All server-side Closeout rules remain unchanged. |

## Schema and rollback

The RC adds two additive migrations:

1. `2026_08_15_010000_create_service_ticket_reopens_table.php`
2. `2026_08_15_020000_allow_direct_invoices.php`

The first creates immutable reopen lineage. The second makes the Invoice's Service Ticket and Billing Handoff references nullable for direct invoices while retaining existing foreign-key behavior for operational invoices.

Rollback order is the reverse migration order. Before rollback, remove or migrate direct invoices that have no Service Ticket/Billing Handoff and preserve any `service_ticket_reopens` records required for audit. Do not roll back on production merely to hide the feature; deploy the prior application build only after confirming its schema compatibility.

## Retained local data preservation

The retained local SQLite database was migrated with `composer phase:update`; it was not reset and `.env` was not replaced. The pre-production backup is ignored from Git and stored locally as `storage/app/backups/rc-v1-0-pre-production.sqlite`.

- SHA-256: `058d5513b7f7514b7e3552ca75d1a5f9e2dbf6dd2e7f296b93bdfc8ea94b4167`
- Isolated restore verification: passed
- SQLite integrity: `ok`
- Tables: 60
- Migrations: 34
- Representative workflow checks: ticket context, closeout/Visit, handoff/ticket, and invoice chain all passed
- Retained records at backup: 1 organization, 1 user, 4 customers, 5 Service Tickets, 8 Visits, 7 Closeouts, 4 Billing Handoffs, 5 Invoices, and 1 payment transaction

## Automated validation

Validation recorded on August 13, 2026:

| Gate | Result |
| --- | --- |
| Full PHPUnit | 286 passed; 2,349 assertions |
| Focused Billing Handoff privacy regression | 14 passed; 119 assertions |
| Composer validation | `composer validate --strict` passed |
| Composer audit | No security vulnerability advisories |
| Pint | Passed |
| Compiled Blade lint | 166 files passed |
| Vite production build | Passed; 56 modules transformed |
| Beta fixture | Exact counts passed: 1 organization, 5 users, 250 customers, 400 locations, 500 tickets, 1,000 Visits, 200 Closeouts, 500 media records, and 3 scenarios |
| Beta hardening | 8 passed; 52 assertions |
| Benchmarks | Today p95 9.7 ms/12 queries; dispatch 9.0 ms/10; ticket detail 15.1 ms/22; review detail 10.9 ms/28; media first byte 0.0 ms |
| Playwright Chromium | 8 passed, 8 intentionally project-skipped; one deterministic worker |
| Accessibility | No serious or critical axe violations in covered desktop/mobile workflows |
| Responsive/mobile | 390×844 Field, invoice presentation, and quick-customer flows passed; 44 px controls and no horizontal overflow verified |
| Diff checks | `git diff --check` passed |
| MySQL 8.4 / GitHub Actions | [Run 31770756699](https://github.com/jonathanodom/ops-portal-v2/actions/runs/31770756699) passed on `2b88529`; migrations, 2,349 assertions, backup/restore, beta fixtures, Playwright/axe, and diff checks passed |

The browser contract now follows the unified Billing redirect and verifies that Closeout is absent while Assigned, appears after On Site, remains compact, and continues to enforce offline write prevention. Playwright uses one worker because the beta suite intentionally shares one seeded organization and throttled users.

The first draft-PR run found the intentional reopen timeline had moved MySQL ticket-detail queries from the pre-RC ceiling of 31 to 32. The focused correction changed only that ceiling; the 750 ms response budget and every other query ceiling stayed unchanged. The green rerun recorded MySQL p95/query results of Today 12.0 ms/12, Dispatch 14.0 ms/10, ticket detail 29.1 ms/32, review detail 18.8 ms/28, and media first byte 0.0 ms.

## Mobile visual review

The screenshots in [the RC UI review](ui-review/release-candidate-v1.0/README.md) were captured at 390×844 from the exact pre-Checkpoint-8 commit `075a136` and the current RC implementation.

## Production acceptance gate

Jonathan must complete and sign off on these scenarios before deployment:

### Field

- [ ] Create a Service Ticket with exactly one technician and confirm automatic lead assignment.
- [ ] Start En Route, arrive On Site, capture time, notes, photos, and parts, then submit a valid Closeout.
- [ ] Confirm Assigned/En Route show no Closeout footer and On Site shows the compact footer/dialog.
- [ ] Complete a return-Visit or callback scenario and verify all prior history remains visible and immutable.

### Billing

- [ ] Approve the Closeout and locate its ready Billing Handoff in Billing / Invoices.
- [ ] Create a handoff-backed Invoice and verify Phase 8.7 labor/trip-charge snapshots and provenance.
- [ ] Create a Direct Invoice and confirm no Service Ticket or Billing Handoff is required.
- [ ] Attach eligible approved Visit labor and verify duplicate attachment is rejected.
- [ ] Remove a Draft/Ready line with a reason; verify Issued line removal is blocked.
- [ ] Issue and present an Invoice; validate cash/check and configured sandbox Square/Stripe behavior without a live charge.

### Data safety and operations

- [ ] Verify production environment values, private disks, queue worker, scheduler, canonical webhooks, and HTTPS before cutover.
- [ ] Run `php artisan migrate --force`, idempotent access-control/Catalog bootstrap, and `php artisan ops:health-scan` in the approved deployment procedure.
- [ ] Rehearse the production database backup and isolated restore using the guarded tooling.
- [ ] Confirm GitHub Actions is green for the exact RC head.
- [ ] Record the deployed SHA, migration output, health scan, smoke-test owner, and rollback decision window.

## Release recommendation

`RC needs another pass` until Jonathan completes the manual production acceptance gate above. Automated local validation and the draft PR's MySQL 8.4 GitHub Actions run are green; this document does not authorize deployment by itself.
