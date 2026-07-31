# Phase 5 Beta Runbook

## Safety boundary

Phase 5 beta is an isolated, resettable rehearsal environment. It never shares a database with the normal local portal. `.env.beta` is untracked; its default database is `database/beta.sqlite`. `beta:setup` refuses to run unless the application environment is `beta`, the configured database name/path contains `beta`, and a SQLite target is inside the repository's `database` directory.

Do not point `.env.beta` at the active development database. Do not add beta seeders to `DatabaseSeeder`.

## Prepare and start

```powershell
Copy-Item .env.beta.example .env.beta
# Set APP_KEY and a local-only BETA_DEMO_PASSWORD in .env.beta.
composer beta:setup
composer beta:serve
```

The beta portal listens on `http://127.0.0.1:8001`, separate from the normal portal. Reset it at any time with `composer beta:setup`; only the isolated beta database and synthetic private objects are disposable.

Role accounts use one password from `.env.beta`:

- `beta.super_admin@newdaytech.test`
- `beta.dispatcher@newdaytech.test`
- `beta.technician@newdaytech.test`
- `beta.reviewer@newdaytech.test`
- `beta.billing@newdaytech.test`

## Automated gate

```powershell
composer beta:validate
php artisan beta:benchmark --env=beta --runs=20 --fail-on-budget
$env:BETA_DEMO_PASSWORD='<same local password>'
npm run test:e2e
Remove-Item Env:BETA_DEMO_PASSWORD
```

`beta:validate` checks exact fixture counts, role assignments, scenario records, SQLite integrity, hardening feature tests, organization isolation, and query ceilings. The benchmark records local warm p95 durations; CI enforces query ceilings but treats machine-dependent timing as recorded evidence. Playwright exercises authenticated desktop and 390×844 mobile screens with axe.

## Manual scenario gate

Reset immediately before the rehearsal. Complete each scenario by its `BETA A`, `BETA B`, or `BETA C` Service Ticket title.

1. Scenario A: as Technician, En Route, On Site, capture time, save a resolved closeout, upload a categorized photo, capture representative acknowledgment, and submit. As Super Admin or Reviewer, approve. As Super Admin or Billing, acknowledge the ready handoff.
2. Scenario B: submit the first visit as needs return trip with all required narrative, equipment, evidence, and acknowledgment. Approve it, schedule and assign its linked planned return, execute and resolve the return, then approve it. Confirm there is one return visit and one final handoff only.
3. Scenario C: submit a valid closeout, return it with correction instructions, revise the next immutable version in Field, add supplemental evidence if useful, resubmit, compare versions, and approve. Confirm version 1, review decision, inherited evidence, and version 2 remain auditable.

For every scenario verify local-time rendering, keyboard navigation, mobile tap targets, offline/write-disable messaging, retry behavior, role separation, audit history, and absence of private evidence from Billing views. Jonathan records sign-off in `docs/PHASE_5_VALIDATION.md`; the PR must remain draft until all three are complete.

## Health operations

Super Admin can open `/office/operations/health`. The screen shows safe incident fingerprints, counts, severity/status, failed-job count, and invariant warnings. It can resolve or reopen a diagnostic incident but cannot retry queues or mutate workflow records.

```powershell
php artisan ops:health-scan
```

Run the scan from a scheduler later if approved. Incident persistence deliberately falls back to structured logs and never interrupts the original request.

## Backup and restore rehearsal

```powershell
php artisan ops:backup --output=storage/app/backups/pre-change.sqlite
php artisan ops:restore-verify storage/app/backups/pre-change.sqlite
Get-FileHash storage/app/backups/pre-change.sqlite -Algorithm SHA256
```

SQLite backup checkpoints WAL before copying. MySQL uses `mysqldump --single-transaction --skip-lock-tables`. A safe manifest records migration/table counts and relationship checks. Restore verification always creates an isolated target, checks integrity, compares the manifest and representative workflow relationships, and removes the temporary target. It never overwrites the configured database.

## Recovery

If an additive migration fails, stop the portal, retain the active database and failed-state logs, and restore the verified backup to a newly named database. Point a temporary environment at it and validate before any approved cutover. Never restore over the active database in place and never use beta reset commands for operational recovery.
