# Commercial Operations V1 — Phase 3 Catalog-Aligned Estimating

## Delivery boundary

Phase 3 extends the owner-approved Phase 2 head `3c85938f5ea720e2ceadc9966002c1eafc7f89a3` on `feat/commercial-operations-v1-checkpoints-0-3`. It resolves Service and Package estimating costs and adds an authorized Quote-to-Catalog creation overlay. Publication, acceptance, conversion, Change Orders, deposit Invoices, inventory, and arbitrary custom Quote lines remain deferred.

## Additive schema

Migration `2026_08_27_050000_add_commercial_estimating_defaults.php` adds:

- Organization-scoped `catalog_labor_roles` with approved hourly internal estimating costs.
- Optional explicit internal cost and default labor-role references on Catalog Services.
- Package pricing-mode and safe cost-source provenance on Quote line snapshots.
- Safe cost-source provenance on Quote Package component snapshots.

The migration is reversible and changes no existing Catalog recipe, Quote revision, Invoice, field proposal, or operational record.

## Cost and pricing resolution

- An explicit Service internal cost is used first and is snapshotted per sales unit.
- Otherwise, an active default labor role supplies hourly Service cost directly.
- A flat Service with an estimated duration scales its labor-role hourly cost using deterministic half-up integer arithmetic.
- Unsupported or incomplete combinations remain unresolved; the system never invents cost.
- Labor roles are functional estimating defaults only and are not employee compensation or payroll data.
- A fixed Package keeps its configured sell price. A component-sum Package deterministically totals the Product and Service sell snapshots in its standard recipe.
- When a component-sum Package enters a Draft Quote, the revision owns an editable component snapshot. Component edits recalculate that Draft only; the canonical Package recipe stays unchanged.
- Locked revisions retain their original sell, cost, provenance, calculations, and content hash after later Catalog changes.

## Authorization and transactional Catalog creation

Existing `catalog.pricing.manage` protects labor-role costs and Service cost defaults. Existing `catalog.manage` protects canonical item creation. The Quote overlay requires `quotes.manage`, `catalog.manage`, and `catalog.pricing.manage`; active membership and explicit denials remain authoritative.

The device-width native dialog creates a canonical Product, Service, or fixed-price Package and then adds its snapshot to the current Draft in one transaction. A validation, stale-version, authorization, or persistence failure leaves neither a partial Catalog item nor Quote line. Existing Catalog routes remain the full workflow for variants, Package recipes, purchase-unit conversions, and advanced metadata.

## Retained local database preservation

Before migration:

- Organizations 1; Users 1; Customers 8; Service Tickets 12; Visits 13; Invoices 13
- Opportunities 0; Commercial Documents 0; Commercial Revisions 0
- Verified backup: `storage/app/backups/ops-20260828-001648.sqlite`
- SHA-256: `829f487b9888dfe780768af3820ccbc127fbafd8edded24302f0d2947934042c`
- Isolated restore verification passed for 94 tables, migrations, counts, relationships, and representative workflows

After the additive migration and idempotent seed, every retained count remained unchanged and `catalog_labor_roles` began empty for owner configuration. `.env` was not replaced and no database reset occurred.

## Owner UI review

1. Open Catalog → Labor costs and create an hourly estimating role.
2. Edit an hourly Service and select that role; edit a flat Service with a duration and select the role. Confirm explicit Service cost takes precedence when entered.
3. Create/edit a Package using Component Sum pricing, add priced Product/Service recipe components, and add it to a Draft Quote.
4. Edit the Quote-owned component quantity and confirm the Quote price/cost updates while the Catalog recipe remains unchanged.
5. From a Draft Quote choose **Create Catalog item**, create each supported item type, and confirm it is selected into the unfinished Quote without leaving the workspace.
6. Confirm a Dispatcher without Catalog management cannot see or call the overlay.
7. Lock the revision, alter the underlying Catalog defaults, and confirm locked history remains unchanged.
8. Review the dialog and Quote at phone and desktop widths for focus return, Escape close, 44px controls, and no horizontal overflow.

## Validation

Focused Phase 3 tests cover hourly/fixed cost resolution, provenance, component-sum Package recalculation, recipe immutability, locked history, overlay authorization, atomic rollback, and retained Phase 2/Catalog behavior. Final complete-suite and CI results are recorded in PR #52.

## Deferred Checkpoint 4

Proposal publication, approval requests, customer presentation, PDF/email, public tokens, comments, customer response/signature, Opportunity automation, Project conversion, Change Orders, deposit Invoices, and inventory/purchasing remain deferred pending explicit owner approval.
