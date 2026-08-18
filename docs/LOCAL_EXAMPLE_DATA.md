# Local Example Data Bootstrap Suite

This suite creates deterministic, disposable workflow data in the retained local development portal. It is deliberately separate from `DatabaseSeeder`, beta fixtures, CI migrations, and deployment.

## Safety contract

The commands run only when all of these conditions are true:

- `APP_ENV=local`.
- The default connection is SQLite.
- The database is exactly `database/database.sqlite` in this repository.
- The database is not in-memory or beta-scoped.
- The selected Organization is active and has exactly one active Super Admin.

Production, beta, MySQL, ambiguous Organizations, inactive Organizations, and missing/ambiguous Super Admin attribution are refused. A PHPUnit-only bypass is disabled by default and cannot relax command guards outside the test environment.

Provider credentials are never generated, enabled, displayed, changed, or cleared. All example email addresses use `.test`, phone numbers use the reserved 555 range, files are synthetic, and no live provider request occurs.

## Commands

Inventory without changing data:

```bash
php artisan examples:inventory --organization=1
```

Create a profile only when no operational or reserved example data exists:

```bash
php artisan examples:bootstrap --organization=1 --profile=small
php artisan examples:bootstrap --organization=1 --profile=full
```

A complete profile is a successful no-op. Partial data, a different profile, or any existing operational data is refused.

Reset and rebuild all operational data for the selected local Organization:

```bash
php artisan examples:reset --organization=1 --profile=small --confirm="RESET LOCAL OPERATIONAL DATA"
```

`examples:reset` is destructive to the selected Organization's operational records. It first creates a checkpointed SQLite backup, writes a SHA-256 checksum and integrity manifest, and restores the backup to a new isolated database. Deletion and rebuilding run in one transaction. Private example objects are deleted only after commit.

## Profiles

The `small` profile contains 8 Customers, 10 Locations, 12 Service Tickets, 13 Visits, 8 Closeouts, 5 private closeout images, 2 private Ticket files, 2 Projects, and 9 Invoices. Its scenarios cover:

- Business, residential, and church/nonprofit Customers; multiple Locations; active, on-hold, and inactive records.
- Unscheduled, scheduled, assigned-today, on-hold, canceled, archived, return, customer-unavailable, pending-review, returned-for-correction, approved, and completed work.
- An Office manual-closeout candidate and a clean En Route/On Site candidate.
- A canceled Visit with completed `system_auto` travel time and no active timer.
- Resolved, needs-return-trip, on-hold, and customer-unavailable Closeout outcomes.
- A PDF and image attached at the Service Ticket level, separate from Closeout evidence.
- A ready Billing Handoff; draft, ready-for-review, unpaid, partially paid, paid, zero-dollar, direct, void, and reissued Invoices; cash/check Payments and Receipts.
- Example Product purchase/base/sales units, TV-mount Service variants, recurring Service enrollments, and a package recipe.
- Installation and indefinite ongoing-support Projects with active, blocked, overdue, done, and planned work.
- Safe open and resolved operational incidents.

The `full` profile preserves the small scenarios and expands to exactly 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, and 500 private-media metadata records. Only a bounded subset is scheduled near today; the remaining data is distributed outside the active dashboard window.

## Package acceptance case

`EXAMPLE-SMART-HOME-ROUGH-IN` is sold as one location while retaining an internal recipe:

| Component | Standard per location | Quantity 5 |
| --- | ---: | ---: |
| Blue Cat6 | 350 ft | 1,750 ft |
| Yellow Cat6 | 350 ft | 1,750 ft |
| 16/2 speaker wire | 175 ft | 875 ft |
| 16/4 speaker wire | 175 ft | 875 ft |

The Customer transaction remains `5 × Integrated Smart Home TV Rough-In`. Cat6 and speaker wire are purchased by 1,000-foot box but consumed and forecast in feet.

## Preservation matrix

| Preserved | Deleted and rebuilt |
| --- | --- |
| Organization identity, timezone, and status | Customers, Contacts, and Locations |
| Existing Super Admin, memberships, roles, and overrides | Recurring Service enrollments |
| Sessions | Service Tickets, Visits, assignments, Closeouts, reviews, and evidence |
| Branding assets and Organization settings | Billing Handoffs, Invoices, Payments, and Receipts |
| Billing, invoice, labor, and payment configuration | Projects, work items, notes, and Ticket links |
| Square/Stripe credentials and connection state | Operational incidents |
| Units of Measure | Subject-specific operational audits |
| Document sequence counters | Catalog rows whose codes begin with `EXAMPLE-` |
| All non-`EXAMPLE-` Catalog customization | Private objects referenced by deleted operational rows |

Document sequence counters are intentionally not reset, so business document numbers are never reused.

## Suggested walkthroughs

1. Open Dashboard and inspect today/attention/billing summaries.
2. Search Customers for `EXAMPLE`, open a Customer, and compare Customer-visible and office-only Location data.
3. Open the assigned-today Service Ticket, then exercise En Route and On Site from Field Today.
4. Open the manual-closeout candidate in Office and validate the guarded admin flow without submitting if the scenario should be reused.
5. Review the pending and returned Closeouts, then inspect the ready Billing Handoff and Invoice states.
6. Open Catalog package quantity `5` and verify the four recipe demand totals above.
7. Open both Projects and inspect linked canonical Service Tickets and organization-local overdue work.

## Review gallery

The gallery is regenerated by `tests/Browser/local-example-screenshots.spec.js` with a short-lived authenticated local session. The session is removed immediately after capture and is never stored in the repository.

| Workspace | Desktop 1440px | Mobile 390px |
| --- | --- | --- |
| Dashboard | ![Populated desktop Dashboard](local-example-data/screenshots/desktop-1440/dashboard.png) | ![Populated mobile Dashboard](local-example-data/screenshots/mobile-390/dashboard.png) |
| Customers | ![Populated desktop Customers](local-example-data/screenshots/desktop-1440/customers.png) | ![Populated mobile Customers](local-example-data/screenshots/mobile-390/customers.png) |
| Service Tickets | ![Populated desktop Service Tickets](local-example-data/screenshots/desktop-1440/service_tickets.png) | ![Populated mobile Service Tickets](local-example-data/screenshots/mobile-390/service_tickets.png) |
| Field Today | ![Populated desktop Field Today](local-example-data/screenshots/desktop-1440/field_today.png) | ![Populated mobile Field Today](local-example-data/screenshots/mobile-390/field_today.png) |
| Review | ![Populated desktop Review](local-example-data/screenshots/desktop-1440/review.png) | ![Populated mobile Review](local-example-data/screenshots/mobile-390/review.png) |
| Billing | ![Populated desktop Billing](local-example-data/screenshots/desktop-1440/billing.png) | ![Populated mobile Billing](local-example-data/screenshots/mobile-390/billing.png) |
| Catalog | ![Populated desktop Catalog](local-example-data/screenshots/desktop-1440/catalog.png) | ![Populated mobile Catalog](local-example-data/screenshots/mobile-390/catalog.png) |
| Projects | ![Populated desktop Projects](local-example-data/screenshots/desktop-1440/projects.png) | ![Populated mobile Projects](local-example-data/screenshots/mobile-390/projects.png) |

## Backup recovery

Every reset reports the verified backup path and checksum. To rehearse it again without touching the active database:

```bash
php artisan ops:restore-verify storage/app/backups/local-examples-before-reset-YYYYMMDD-HHMMSS-XXXXXX.sqlite
```

Recovery of the active database is intentionally not automated by `examples:reset`. Stop the application, preserve the current file, and restore a verified backup only through an explicitly approved recovery procedure.

## Validation record

- Additive Service Ticket file migration applied after a verified pre-suite backup; `.env` was unchanged.
- Small profile bootstrap/no-op and guarded reset completed successfully.
- Full profile reset completed twice with exact counts; measured local rebuilds were approximately 4.1 and 4.3 seconds.
- Final retained local profile: `small`.
- Every reset backup passed isolated SQLite integrity, migration, count, relationship, and representative-workflow comparison.
- Focused suite: 4 tests, 25 assertions passed.
- Package quantity-five demand and zero-active-timer invariants passed.
- Full regression: 324 tests and 2,642 assertions passed.
- Compiled Blade syntax: 187 files passed; Pint, Composer validation/audit, Vite production build, and diff checks passed.
- Beta fixture validation passed at its existing exact counts. Local warm p95 benchmarks remained under 21 ms for the measured queues/details.
- Playwright/axe responsive suite: 18 passed and 16 intentionally project/environment-filtered tests skipped. Local screenshot gallery: 2 passed.
