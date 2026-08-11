# Phase 8 Checkpoint 2 Validation

## Scope

Checkpoint 2 implements Phase 8C Products only:

- Organization-scoped Product records with customer and internal descriptions.
- Base consumption UOM, default sales UOM, and fixed-point quantity relationships.
- Product-specific purchase-unit conversions for boxes, rolls, bags, cases, or other configured UOMs.
- Integer-cent cost, purchase-pack cost, and default sell-price fields protected by Catalog pricing authority.
- Responsive Office Product list, form, detail, and purchase-unit management views.

Packages/assemblies, package demand, field/invoice selection, immutable transaction snapshots, customer subscriptions, quantity on hand, stock movements, warehouses, truck stock, receiving, purchasing, and accounting integrations are excluded.

## Additive schema

Migration `2026_08_11_010000_create_catalog_product_tables` adds:

- `catalog_products`
- `catalog_product_purchase_units`

Follow-up migration `2026_08_11_020000_add_description_to_catalog_categories` adds one nullable description column to the existing Category table so administrators can document and search the intended contents of each Category.

No existing migration or operational table is changed. Both tables carry `organization_id`; mutable records use active/inactive state and have no hard-delete routes.

Product quantities use fixed-point thousandths. Money remains unsigned integer cents. A Product can classify future tracking as standard, serialized, or lot/roll, but this creates no inventory quantity or transaction behavior.

## Wire conversion acceptance

Blue Cat6 can use Foot as both its base and default sales unit while using Product-specific Box purchase options:

| Purchase option | Stored base quantity | One pack converts to |
|---|---:|---:|
| 250 ft box | 250,000 millis | 250.000 ft |
| 500 ft box | 500,000 millis | 500.000 ft |
| 1,000 ft box | 1,000,000 millis | 1,000.000 ft |

The conversion service also supports fractional purchase quantities with checked integer arithmetic and half-up rounding. No generic Box-to-Foot conversion exists because the relationship belongs to the Product.

## Authorization

Checkpoint 2 reuses the approved Catalog capabilities:

| Action | Capability |
|---|---|
| View Products and conversions | `catalog.view` |
| Create/update/deactivate Products and conversions | `catalog.manage` |
| Change cost, pack cost, sell price, and tax defaults | `catalog.pricing.manage` |

Super Admin retains every Catalog capability. Dispatcher and Billing retain view/use, Technician retains field-oriented view/use for later integration, and Reviewer retains view. The Office shell still requires `experience.office.access`; explicit membership grants/denials and inactive-membership restrictions remain authoritative.

## Data preservation

Before migration, the development database contained 51 tables and the following representative counts:

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
| Units of Measure | 10 | 10 |
| Catalog Services | 1 | 1 |
| Catalog Products | Not present | 0 |

Backup:

- File: untracked `storage/app/backups/phase8-checkpoint2-pre-migration.sqlite`
- SHA-256: `A0DF2C34BD1C3034D110CA89E4ECCC732A65C92340E46C08E6654B057357B494`
- Isolated migration manifest and 51-table count verification: passed

The current workstation development connection is SQLite. The additive migration is applied directly after backup verification; CI continues to run the complete migration chain against MySQL 8.4. The operational `.env` is not replaced.

## Automated validation

- Checkpoint Product feature tests: 7 passed, 56 assertions.
- Complete PHPUnit suite: 135 passed, 1,063 assertions.
- Beta fixture validation: passed with 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, and 500 media metadata records.
- Beta hardening: 8 passed, 50 assertions.
- Local five-run benchmark: Today p95 10.8 ms / 18 queries; Dispatch p95 21.1 ms / 16 queries; Ticket Detail p95 31.0 ms / 21 queries; Review Detail p95 31.7 ms / 26 queries; media first-byte p95 0.2 ms.
- Playwright Chromium/axe: 8 passed, 8 expected opposite-project skips. Product coverage creates foot-based Cat6, adds a 250-foot Box conversion, and checks Product Catalog workspace behavior at 390, 768, 1,440, and 1,920 pixels with no serious/critical axe findings.
- Composer validation: passed, strict. Composer audit: no advisories.
- Pint: passed.
- Compiled Blade syntax: 153 files passed.
- Vite production build: passed.
- Git diff check: passed.

## Audit and privacy

Product and purchase-unit create/update events store record IDs and changed field names only. Cross-organization Product and nested purchase-option attempts return 404 and create a safe rejected-access event. Audit metadata omits descriptions, cost/price input values, and vendor identifiers.

## Rollback

The migration `down()` drops only the two new Product tables in dependency order. Rolling it back removes Product data but does not alter Services, Customers, Service Tickets, Visits, Closeouts, Invoices, Payments, organization identity, or existing UOMs.

Restore of the active development database is not expected. If recovery becomes necessary, the backup must be verified against a separate target before any owner-approved restore; the active database is never reset as part of Checkpoint 2.

## Next gate

Stop after review of Checkpoint 2. Checkpoint 3 may add Packages/Assemblies, standard recipes, and the Integrated Smart Home TV Rough-In demand acceptance case only after approval. Inventory balances, warehouse/truck stock, purchasing, and field/invoice integration remain out of scope.
