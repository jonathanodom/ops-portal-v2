# Phase 8 Checkpoint 4 Validation

## Scope

Checkpoint 4 implements Phase 8E Field and Invoice Integration only:

- One responsive, search-first Catalog picker shared by field closeout and editable Invoice contexts.
- Service, explicit Service Variant, Product, Package, and fixed-point quantity selection.
- Immutable Catalog identity, description, UOM, price, tax, Variant, actor/time, and Package-recipe snapshots.
- Approved field-selection flow into the ticket-wide Phase 6 invoice generator.
- Reasoned, audited Invoice price overrides without changing Catalog defaults or field source records.
- Existing custom field proposals and custom manual Invoice Lines remain available.

Customer Service enrollment, recurring billing automation, quantity on hand, actual consumption, warehouses, truck stock, stock movements, receiving, purchasing, proposal builders, project BOM allocation, and accounting integrations remain excluded.

## Additive schema

Migration `2026_08_12_010000_add_catalog_snapshots_to_field_and_invoice_lines` adds nullable typed snapshot columns to the existing `visit_part_proposals` and `invoice_lines` tables. No existing field is removed or repurposed.

Both records can retain:

- Catalog type and nullable Service, Variant, Product, or Package source references.
- Code, name, customer description, UOM code/name, and selected fixed-point quantity.
- Original and selected integer-cent unit price plus tax default.
- Selection actor and server timestamp.
- Internal Package recipe and expected-demand JSON snapshot.

Custom/manual records keep these columns null. Issued and void Invoice immutability continues to be enforced by `InvoiceWorkflow`; void/reissue copies the complete snapshot without rereading Catalog data.

## Selection and provenance

`CatalogLineSnapshotFactory` resolves only active records inside the active Organization. Variant selection must belong to the selected variant-priced Service. It delegates Service price resolution to `CatalogPricingResolver` and Package demand to `PackageDemandCalculator`.

Field selection creates a typed `VisitPartProposal` on the active draft. This deliberately reuses established correction-version copying, review adjustment, removal, and Invoice-generation behavior. The picker exposes no price input or displayed price to field users. A submitted field price is impossible because the server constructs the snapshot.

Billing may select Catalog items directly on an editable Invoice. A source price change requires the existing Invoice-management authority and a reason. Effective Invoice price changes do not mutate the original Catalog price snapshot. Safe audit metadata contains IDs, type, quantity, and changed field names—not descriptions, pricing reasons, recipe text, internal notes, or customer information.

## Package acceptance

Selecting `Integrated Smart Home TV Rough-In` at Qty 5 creates one customer Invoice Line:

`5 × Integrated Smart Home TV Rough-In`

Its internal immutable snapshot retains the 175-foot pull basis and expected standard demand:

| Product | Expected demand |
|---|---:|
| Blue Cat6 | 1,750 ft |
| Yellow Cat6 | 1,750 ft |
| 16/2 speaker wire | 875 ft |
| 16/4 speaker wire | 875 ft |

The recipe is visible only in the authorized Billing editor and never appears in customer presentation, PDF, Reviewer summary, or field price controls.

## Authorization

| Operation | Required authority |
|---|---|
| See/use field picker | Existing visit execution plus `catalog.use` |
| Add Catalog Invoice Line | `invoices.manage` plus `catalog.use` |
| Edit an Invoice transaction price | `invoices.manage` and required reason |
| Change Catalog defaults | `catalog.pricing.manage` |
| View issued customer document | Existing Invoice presentation policy |

Super Admin retains all access. Billing can use the Invoice picker. Technician can use the field picker only on an executable Visit and cannot submit prices. Reviewer retains read-only Invoice summary behavior. Explicit capability denial and inactive membership restrictions remain authoritative.

## Local preservation

Before migration, guarded backup and isolated restore verification completed:

- Backup: untracked `storage/app/backups/phase8-checkpoint4-pre-migration.sqlite`
- SHA-256: `281FEF9312D95130E1F59779C9841F26307F0F4C4FE523FC47AF24676A357182`
- Restore verification: SQLite integrity `ok`; 56 tables; migrations, counts, relationships, and representative workflows matched.

The migration added columns only. It created no Catalog selections and retained the active counts: 1 Organization, 4 Customers, 5 Service Locations, 4 Service Tickets, 7 Visits, 5 Closeouts, 2 Billing Handoffs, 1 Invoice, 2 Invoice Lines, 0 Visit proposals, 1 Catalog Service, 0 Products, and 0 Packages. The operational `.env` was not replaced.

## Automated validation

- Catalog integration feature tests: 7 passed, 73 assertions. This includes Service Variants, Products, Packages, custom lines, field selection, reasoned price override, organization isolation, correction versions, void/reissue, and the approved field-to-Invoice path.
- Complete PHPUnit suite: 149 passed, 1,227 assertions.
- Beta fixtures: exact deterministic profile passed (250 Customers, 400 Service Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, 500 private-media metadata records).
- Beta validation: 8 passed, 50 assertions; SQLite integrity `ok`.
- Local warm benchmark (20 runs): Today p95 12.4 ms / 18 max queries; Dispatch p95 22.2 ms / 16; Service Ticket detail p95 19.9 ms / 21; review detail p95 18.4 ms / 26; private-media first byte p95 0.2 ms.
- Playwright Chromium/axe: 8 applicable scenarios passed and 8 opposite-project scenarios skipped as designed. The field Catalog picker was exercised at 390 Ã— 844 with full-viewport sizing, focus return, no horizontal overflow, field-price exclusion, and no serious or critical axe violation.
- Composer validation and audit: passed; no known security advisories.
- Pint: passed.
- Compiled Blade syntax lint: 150 files passed.
- Vite production build: passed (56 modules transformed).
- Diff and repository hygiene checks: passed; no generated test artifacts, beta database, backup, or `.env` file is tracked.

## Rollback

The migration `down()` removes only the new indexes, foreign keys, and nullable snapshot columns from `invoice_lines` and `visit_part_proposals`. It does not alter Catalog definitions, existing manual line fields, Customers, Service Tickets, Visits, Closeouts, Billing Handoffs, Invoices, Payments, or organization settings. Rolling back after creating Checkpoint 4 selections would intentionally discard only their Catalog provenance fields; use the verified backup when preservation is required.

## Next gate

Stop after Checkpoint 4 review. Checkpoint 5 / Phase 8F customer recurring-Service enrollment requires explicit later approval. It must remain separate from automatic payment charging and cannot introduce inventory behavior.
