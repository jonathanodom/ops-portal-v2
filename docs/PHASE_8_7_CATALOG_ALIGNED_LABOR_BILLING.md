# Phase 8.7 Catalog-Aligned Labor Billing

## Baseline and scope

Phase 8.7 branches from merged Phase 8.6 commit `02802be92f069ed3fdc9fef0b33e19ce8c9a09c4`. Catalog is authoritative for newly generated labor and trip-charge pricing; Billing Settings owns time-calculation and recommendation policy; Invoice lines retain immutable commercial and operational snapshots. Historical `BillingLaborRate` records, `invoice_lines.labor_rate_id`, issued Invoices, PDFs, Closeout evidence, and payment behavior remain intact.

Phase 8.8 import/export and Phase 9 are not included.

## Additive schema

Three reversible migrations were added:

- `2026_08_14_010000_add_labor_policy_to_organization_billing_settings.php` adds the default hourly Catalog Service, increment, rounding, minimum time, Trip Service, suggestion, and auto-selection policy.
- `2026_08_14_020000_create_closeout_review_trip_charges_table.php` stores one immutable reviewer-selected trip charge per Closeout Review with Visit, Catalog Service/Variant, recorded travel duration, commercial snapshot, actor, and timestamp.
- `2026_08_14_030000_add_trip_charge_provenance_to_invoice_lines.php` adds `source_travel_seconds` to invoice lines.

Rollback removes only these Phase 8.7 policy/provenance fields and records. It does not remove Catalog, legacy rates, operational records, Invoices, or Payments. Rolling back after trip-charge approvals intentionally discards Phase 8.7 review provenance; use a verified backup when retention is required.

## Catalog and billing policy

The finalized NewDay labor Catalog is:

| Code | Service | Hourly price |
|---|---|---:|
| `LABOR-RES-IT` | Residential IT / Computer Support | $95.00 |
| `LABOR-RES-TECH` | Residential Technology Service | $115.00 |
| `LABOR-BUS` | Business Service Labor | $135.00 |
| `LABOR-PROJECT` | Project / Installation Labor | $145.00 |
| `LABOR-ENG` | Engineering / Programming | $165.00 |

`TRIP` is a variant-priced Trip / Dispatch Service using the Visit UOM. `TRIP-45-60` snapshots $45.00 and `TRIP-60-PLUS` snapshots $65.00. Existing customized Catalog prices and taxability are never overwritten by bootstrap.

Billing Settings permits exact, 15-, 30-, or 60-minute increments; `up`, `nearest`, or `down` rounding; and a zero or configured minimum. Nearest uses deterministic half-up midpoint behavior. The minimum is applied after rounding, but zero approved minutes always remain zero and create no labor line.

The Phase 8.7 resolver currently supports an explicit valid Catalog override and then the organization default. The abstraction leaves customer-agreement/service-plan pricing for a later approved phase.

## Invoice generation and historical compatibility

New labor follows:

`approved on-site + other time -> Billing policy -> Catalog service snapshot -> Invoice line`

Generated lines retain Visit, Closeout, Review, quantity, selected/default prices, unit, taxability, Catalog source, selecting actor/time, and customer-safe descriptions. `labor_rate_id` is null. Live Catalog changes do not alter the draft snapshot or issued history.

Legacy invoice lines continue resolving their `BillingLaborRate`. The compatibility command is:

```powershell
php artisan billing:migrate-legacy-labor
php artisan billing:migrate-legacy-labor --organization=1
```

It leaves an existing Catalog default alone. Otherwise it reuses one exact active hourly name/price/Hour-UOM match or creates `LEGACY-LABOR-{rate ID}`. Multiple exact matches and incompatible stable-code conflicts are reported without writes. Other active legacy rates are reported for human interpretation; no rate or historical line is removed.

## Trip review and provenance

The recommender uses the union of completed travel entries clipped to the Visit's En Route-to-On Site interval. Overlapping crew intervals are not double-counted; onsite, generic Visit duration, drive-home time, active timers, and mileage are excluded.

| Recorded En Route travel | Recommendation |
|---|---|
| 0:00–44:59 | None |
| 45:00–59:59 | `TRIP-45-60`, $45.00 default Catalog price |
| 60:00+ | `TRIP-60-PLUS`, $65.00 default Catalog price |

Billing Review displays the recorded duration and a 44px-minimum checkbox. Suggestions are off by default; the organization may enable reversible preselection. Approval recomputes eligibility on the server. Selection stores the Visit, duration, Service/Variant, price/tax/UOM snapshot, reviewer, and time. Invoice creation copies that approved snapshot into one travel line with Visit/Closeout/Review provenance; it never reads the current Catalog price. A visible but unselected suggestion produces no charge.

## Production bootstrap

The narrow, idempotent production command is:

```powershell
php artisan catalog:bootstrap-newday
php artisan catalog:bootstrap-newday --organization=1
```

It creates required UOMs/categories, the five finalized labor Services, `TRIP`, and its two Variants. Existing compatible stable codes are skipped without changing customized prices or taxability. Structural conflicts are retained, reported, and cause a nonzero exit for administrator review. The command does not create Products, Packages, recurring Services, or generalized import/export records.

After bootstrap, an administrator must select the intended default labor Service and Trip Service under **Settings > Billing**, then confirm increment, rounding, minimum, and suggestion policy. Bootstrap intentionally does not decide organization policy.

## Preservation and validation

Before the Checkpoint 8 migration, a recoverable SQLite backup was created and restored in isolation with matching migrations, table counts, relationships, and representative workflows. SHA-256: `5C9EB6DCD7DD76D4FF874D96F3A00FF0557946F51089C1012DD356C36A2BCA9B`.

The final retained-database rehearsal produced `storage/app/backups/phase87-final-20260813-170401.sqlite` (untracked), SHA-256 `D89D944F105FAB71972BA414320410651D9C65A2FC913D219A44A5A08FFF8315`. Isolated restore verification passed across 59 tables. `.env` and existing operational records were not reset or replaced.

Final local results on August 13, 2026:

- PHPUnit: 256 passed, 1,994 assertions.
- Focused production bootstrap: 14 passed, 135 assertions.
- Composer validation: passed.
- Composer audit: no known vulnerability advisories.
- Pint: passed.
- Compiled Blade lint: 162 files passed.
- Vite production build: passed.
- Beta fixture: 1 organization, 5 users, 250 Customers, 400 locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, and 500 media records; validation passed.
- Beta benchmark (10 runs): Today p95 13.1 ms / 18 queries; Dispatch p95 13.4 ms / 16 queries; Service Ticket detail p95 15.0 ms / 21 queries; Review detail p95 14.4 ms / 28 queries; media first byte p95 0.1 ms.
- Playwright/axe: 8 applicable scenarios passed and 8 opposite-project viewport scenarios skipped as designed; no serious or critical violations.
- Backup/isolated restore: passed.
- `git diff --check`: passed.

The Beta benchmark initially exposed direct controller invocation that bypassed method injection. `TripChargeRecommender` was moved to `CloseoutReviewController` constructor injection; focused tests and the complete Beta validation/benchmark then passed.

GitHub Actions repeats fresh MySQL 8.4 migrations, seeding, PHPUnit, backup/restore, Beta fixture/benchmark, and Playwright/axe. Record the final run in the draft PR before freezing Phase 8.7.

## Acceptance gate and limitations

Jonathan must manually verify one approved Visit with billable time and a selected trip recommendation through Invoice draft creation. Confirm the labor Service/price, calculation policy, trip checkbox behavior, immutable source details, and customer-facing wording before approval.

No generalized Catalog import/export, inventory, purchasing, proposal pricing, customer agreements, technician pay rates, mileage, recurring billing redesign, production deployment, Phase 8.8, or Phase 9 work is included.
