# Phase 8 Checkpoint 1 Validation

## Scope

Checkpoint 1 implements Phase 8A Catalog Foundation and Phase 8B Services only:

- Organization-scoped Categories and reusable Units of Measure.
- Service records, six explicit pricing models, recurring definitions, TV-mounting-style Variants, and related-Service add-ons.
- Capability- and policy-enforced Office Catalog management.
- Integer-cent pricing, taxable defaults, safe audit events, responsive Office views, and accessibility coverage.

Products, purchase conversions, Packages, package demand, field/invoice selection, immutable transaction snapshots, and customer subscriptions are excluded.

## Additive schema

Migration `2026_08_10_010000_create_catalog_foundation_tables` adds:

- `catalog_categories`
- `units_of_measure`
- `catalog_services`
- `catalog_service_variants`
- `catalog_service_addons`

No existing table or migration was changed. All new records carry `organization_id`; mutable business records use active/inactive state and have no hard-delete routes.

## Capabilities

| Role | catalog.view | catalog.use | catalog.manage | catalog.pricing.manage |
|---|---:|---:|---:|---:|
| Super Admin | Yes | Yes | Yes | Yes |
| Dispatcher | Yes | Yes | No | No |
| Technician | Yes | Yes | No | No |
| Reviewer | Yes | No | No | No |
| Billing | Yes | Yes | No | No |

Technician field lookup is intentionally deferred to 8E. Explicit membership grants/denials and inactive-membership enforcement remain authoritative.

## Data preservation

Before migration, the development database contained:

| Record | Count before | Count after |
|---|---:|---:|
| Organizations | 1 | 1 |
| Customers | 4 | 4 |
| Service Locations | 5 | 5 |
| Service Tickets | 4 | 4 |
| Visits, including archived | 7 | 7 |
| Closeouts | 5 | 5 |
| Billing Handoffs | 2 | 2 |
| Invoices | 1 | 1 |
| Invoice Lines | 2 | 2 |
| Payment Transactions | 0 | 0 |

The additive migration created 10 default UOM records for the existing Organization and no demo Categories or Services. The operational `.env` was not replaced.

Backup:

- File: untracked `storage/app/backups/phase8-checkpoint1-pre-migration.sqlite`
- SHA-256: `93fa3b5cba9ae05112c5c826bdd4e83b92ea0155dd927b30a07801370ca1d4e0`
- SQLite integrity: `ok`
- Restored tables: 47
- Isolated manifest comparison: passed for migrations, counts, relationships, and representative workflows

The current workstation development connection is SQLite, so the migration and seeding steps were run directly with `artisan migrate --force` and `artisan db:seed --force`. CI remains configured to repeat migrations and tests against MySQL 8.4.

## Automated validation

- Checkpoint feature tests: 7 passed, 55 assertions.
- Complete PHPUnit suite: 127 passed, 994 assertions.
- Beta fixture validation: passed with 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, and 500 media metadata records.
- Beta hardening: 8 passed, 50 assertions.
- Playwright Chromium/axe: 8 passed, 8 expected opposite-project skips.
- Catalog browser coverage: Services creation, Variant creation, Services/Categories/Units at 390, 768, 1440, and 1920 pixels; no overflow, desktop/mobile presentation switching, 44px controls, and no serious or critical axe findings.
- Composer validation: passed, strict.
- Composer security audit: no advisories.
- Pint: passed.
- Compiled Blade syntax: 142 files passed.
- Vite production build: passed.
- Git diff check: passed.

## Rollback

The Checkpoint 1 migration `down()` drops only the five new Catalog tables, in dependency order. Rolling it back removes Catalog data but does not alter Customers, Service Tickets, Visits, Closeouts, Invoices, Payments, or organization identity.

Restore of the active development database is not required because migration and preservation checks passed. If recovery becomes necessary, verification must target a separate database first; never overwrite the active database without explicit owner approval.

## Next gate

Stop after review of Checkpoint 1. Checkpoint 2 may add Products, base/sales units, and Product-specific purchase-unit conversions only after approval. Quantity-on-hand inventory, warehouses, truck stock, receiving, purchasing, and Package recipes remain out of scope for Checkpoint 2.
