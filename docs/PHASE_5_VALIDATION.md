# Phase 5 Validation Record

## Delivered scope

Phase 5 adds one reversible `operational_incidents` migration, request correlation, structured safe incident capture, health scans/UI, isolated beta fixtures, guarded backup/restore verification, query instrumentation, Playwright/axe coverage, an accessible pagination view, and operational runbooks. No business module, beta/production import, deployment, or external integration was added.

## Authorization

| Capability | Super Admin | Dispatcher | Technician | Reviewer | Billing |
| --- | --- | --- | --- | --- | --- |
| `operations.health.view` | Yes | No | No | No | No |
| `operations.health.manage` | Yes | No | No | No | No |

Explicit membership deny/grant overrides retain precedence. Health routes require an active organization membership and return 404 for another organization's incident identifiers. Global queue incidents disclose safe metadata only. Super Admin retains every portal capability.

## Fixture profile

- Five beta-only role accounts and three resettable workflow scenarios.
- 250 customers, 400 service locations, 500 Service Tickets, 1,000 visits, 200 submitted closeouts, and 500 private-media metadata rows.
- Synthetic values and generated local objects only; one 5 MB object supports first-byte measurement.
- Beta seeders are not reachable through normal `db:seed` or `phase:update`.

## Local preservation and recovery

Before migration, the Phase 4 database had 12 migrations and retained: organizations 1, users 1, memberships 1, customers 1, contacts 1, locations 2, tickets 1, visits 1, assignments 1, closeouts 1, reviews 1, and handoffs 1.

- Initial backup: `phase4-before-phase5-20260731-130740.sqlite`, 471,040 bytes, SHA-256 `C4967D314350BA6CFCC4F2BD57B0B3A6E55D9EC4EFB17D6371AC2F40F824B653`; SQLite integrity `ok` and representative counts verified on an isolated copy.
- Guarded-tool rehearsal: `phase4-command-rehearsal.sqlite`, SHA-256 `B1377CBE8920FE39DB97D4719BC2C414924131599E673B8E02F22883AE0A5206`; isolated restore integrity `ok`.
- The new migration and idempotent capability seed first passed on an isolated database copy, then on the active database.
- Post-migration operational counts exactly match the inventory above; `operational_incidents` starts at 0. `.env` was not replaced.
- `composer phase:update` was invoked, but Docker is unavailable/stalled on this workstation. Its remaining additive migrate, idempotent seed, dependency, and production-build steps were run individually. No reset command touched the active database.

Rollback removes only `operational_incidents`. Reverting capability seeds is not automatic because explicit membership overrides may depend on the capability rows; prefer forward correction. Restore verification never overwrites the active target.

## Automated results

Recorded July 31, 2026 on PHP 8.4.20 / Laravel 13.23.0 / local SQLite beta:

- Composer strict validation: passed.
- PHPUnit: 65 tests, 412 assertions passed.
- Pint: passed with no formatting differences.
- Blade compilation and PHP syntax: 102 compiled files passed.
- Production Vite build: passed.
- Deterministic beta fixture/integrity validation: passed at every exact count.
- Authenticated Playwright Chromium/axe: desktop and 390×844 mobile projects passed; expected inverse-project tests skipped; no serious/critical axe findings, no horizontal overflow, and field effective tap targets meet 44px.
- SQLite backup/isolated restore: passed. MySQL 8.4 backup/restore, migrations, full suite, beta fixtures, and Playwright are enforced in CI.

Twenty-run local warm benchmark recorded before the final schedule-density adjustment:

| Surface | Warm p95 | Maximum queries | Budget |
| --- | ---: | ---: | ---: |
| Today | 79.0 ms | 17 | 500 ms / 20 queries |
| Dispatch | 56.9 ms | 16 | 500 ms / 25 queries |
| Service Ticket detail | 9.5 ms | 16 | 750 ms / 30 queries |
| Review detail | 8.6 ms | 22 | 750 ms / 35 queries |
| Private media first byte | 0.0 ms | n/a | 1,000 ms |

The final twenty-run benchmark after reducing near-term synthetic queue density also passed: Today 9.9 ms/17 queries, Dispatch 22.5 ms/16, ticket detail 10.2 ms/16, review detail 14.9 ms/22, and media first byte 0.1 ms. Wall-clock results are evidence only; CI enforces stable query ceilings and required migrations/indexes.

## Incident privacy and behavior

Persisted context is allowlisted to route/state identifiers, safe reason codes, field names, record IDs, age, driver/connection, and job class. It excludes narratives, addresses, contacts, access instructions, media contents/paths, raw exception text, review reasons, and submitted values. Fingerprints deduplicate recurring incidents and increment occurrence counts. Persistence failure writes a safe structured warning and cannot recurse into the original failure.

## Manual owner gate

- [ ] Jonathan completed Scenario A and confirmed it is understandable, repeatable, and auditable.
- [ ] Jonathan completed Scenario B and confirmed the linked return workflow is understandable, repeatable, and auditable.
- [ ] Jonathan completed Scenario C and confirmed correction/version history is understandable, repeatable, and auditable.
- [ ] Jonathan verified role separation plus Super Admin single-user operation.
- [ ] Jonathan recorded owner sign-off and date.

The Phase 5 PR must remain draft and must not merge or claim production readiness until every box is complete.

## Known limitations and exclusions

Docker/MySQL could not run locally; CI owns MySQL 8.4 parity. There is no external monitoring, queue retry UI, production scheduler, malware scanning, production object storage, offline sync, notifications, maps/calendars, accounting, invoices/payments, inventory mutation, CRM import/sync, completed-ticket reopening, beta modification, or deployment. See `PHASE_5_DECISION_LOG.md` for revisit triggers.
