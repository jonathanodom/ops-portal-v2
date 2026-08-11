# Phase 8 Checkpoint 3 Validation

## Scope

Checkpoint 3 implements Phase 8D Packages/Assemblies only:

- Organization-scoped sellable Packages with flat or quote-required pricing.
- Product and Service recipe components with explicit UOM and fixed-point standard quantity.
- Direct Product quantities or an explainable pull-count × standard-allowance basis.
- Optional Product waste in basis points and separate standard/planning demand.
- Reusable `PackageDemandCalculator` domain logic.
- Responsive Office Package list, form, detail, recipe management, and demand preview.

Field/invoice selection, transactional snapshots, actual consumption, nested Packages, customer subscriptions, quantity on hand, stock movements, warehouses, truck stock, receiving, purchasing, proposal builders, project BOM allocation, and accounting integrations are excluded.

## Additive schema

Migration `2026_08_11_030000_create_catalog_package_tables` adds:

- `catalog_packages`
- `catalog_package_components`

The additive follow-up migration `2026_08_11_040000_add_quantity_basis_to_catalog_package_components` adds the optional pull-allowance basis fields without rewriting the Package tables or existing components.

No existing migration or operational table is changed. Both records carry `organization_id`, creator/updater attribution, active state, and no hard-delete routes. Component records retain either a Product or Service source plus an explicit component UOM. Product components can store `direct` quantity or `pull_allowance` with fixed-point pull count and quantity per pull; the controller deterministically persists their resolved standard quantity. Service components remain direct. Nested Package references and actual-consumption fields do not exist.

Money remains unsigned integer cents. Recipe quantities use thousandths (`1000` = one whole UOM). Waste uses basis points (`500` = 5%).

## Integrated Smart Home TV Rough-In acceptance

The acceptance Package is sold per Location with a 175-foot standard pull allowance. Its recipe retains the pull count and allowance independently rather than flattening them into an unexplained total:

| Product | Pulls per location | Standard per location | Qty 5 standard demand |
|---|---:|---:|---:|
| Blue Cat6 | 2 | 350 ft | 1,750 ft |
| Yellow Cat6 | 2 | 350 ft | 1,750 ft |
| 16/2 speaker wire | 1 | 175 ft | 875 ft |
| 16/4 speaker wire | 1 | 175 ft | 875 ft |

Total standard cable demand for Qty 5 is 5,250 ft. The customer-facing transaction remains `5 × Integrated Smart Home TV Rough-In`; the calculator creates no Invoice or Invoice Line.

## Standard, planning, and actual

- Standard demand is the immutable meaning of the current recipe definition multiplied by Package quantity.
- Planning demand applies an optional component-specific waste percentage after scaling standard demand.
- Actual consumption is not a Package field and is not implemented. A future execution/job-cost record must store actual usage separately.

Example: 175 ft standard with 5% waste and Qty 5 produces 875 ft standard demand and 918.750 ft planning demand. The stored 175 ft recipe remains unchanged.

## Authorization and scoping

Checkpoint 3 reuses the approved Catalog capabilities:

| Action | Capability |
|---|---|
| View Packages, recipes, and demand | `catalog.view` |
| Create/update/deactivate Packages and components | `catalog.manage` |
| Change Package price, pricing model, and tax default | `catalog.pricing.manage` |

All Package, component, Product, Service, Category, and UOM selections are resolved inside the active Organization. Cross-organization route identifiers return 404 with safe rejected-access auditing; forged component selections fail validation. Explicit membership overrides and inactive memberships remain authoritative.

## Data preservation

The local SQLite database moved from 53 to 55 tables. The Package migration and idempotent Catalog seeder created no demo Package or component records and did not change existing record counts:

| Record | Before | After |
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
| Catalog Categories | 18 | 18 |
| Units of Measure | 10 | 10 |
| Catalog Services | 1 | 1 |
| Catalog Products | 0 | 0 |
| Catalog Packages | Not present | 0 |
| Package Components | Not present | 0 |

Backup:

- File: untracked `storage/app/backups/phase8-checkpoint3-pre-migration.sqlite`
- SHA-256: `4CBB08C1EE60C9C6799B4A67B2B1E5884C7032732D3D49C43D60D9F61C023EBD`
- Isolated migration manifest: verified through the Category-description migration, with the Package migration pending as expected

The operational `.env` was not replaced. The current workstation uses SQLite; CI continues to run the complete migration chain against MySQL 8.4.

## Automated validation

- Package feature tests: 7 passed, 91 assertions.
- Complete PHPUnit suite: 142 passed, 1,154 assertions.
- Beta fixture validation: passed with 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, and 500 media records; SQLite integrity `ok`.
- Beta hardening tests: 8 passed, 50 assertions.
- Warm beta benchmark (five runs): Today 11.5 ms p95 / 18 max queries; Dispatch 22.4 ms / 16; Service Ticket detail 32.3 ms / 21; Review detail 30.3 ms / 26; authorized media first byte 0.1 ms p95.
- Playwright Chromium/axe: 8 passed and 8 expected opposite-viewport skips; no serious or critical accessibility violations.
- Composer validation: passed strict validation.
- Composer audit: no security vulnerability advisories.
- Pint: passed.
- Compiled Blade lint: 149 generated PHP views passed syntax validation.
- Vite production build: passed.
- Git diff whitespace/error check: passed.

## Audit and privacy

Package and recipe-component events store Package/component/source IDs, component type, state names, and changed field names only. Audit metadata omits descriptions, internal notes, prices, recipe narrative, and customer-facing content.

## Rollback

Rollback first removes the pull-basis columns through the `040000` migration and then drops only Package components and Packages through `030000`, in dependency order. It does not alter Services, Products, UOMs, Customers, Service Tickets, Visits, Closeouts, Invoices, Payments, organization identity, or existing Catalog records.

## Next gate

Stop after review of Checkpoint 3. Checkpoint 4 may add permission-aware field/Office Catalog selection and immutable transaction snapshots only after approval. Manual invoice lines must remain available. Inventory, purchasing, actual consumption, and subscriptions remain out of scope.
