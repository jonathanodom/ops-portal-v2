# Products & Services Catalog Architecture

## Checkpoint status

Phase 8 Checkpoint 1 implements the organization-scoped Catalog foundation and Services domain. Products, purchase-unit conversions, Packages, field/invoice selection, transactional snapshots, and customer subscriptions are not implemented yet.

## Catalog boundaries

- A Service describes labor or a standardized customer outcome.
- A Product will describe a physical item with a base consumption unit and Product-specific purchase conversions in Checkpoint 2.
- A Package will describe one customer-facing sale with an internal Product/Service recipe in Checkpoint 3.
- A catalog definition is a current default. Checkpoint 4 will create immutable transaction snapshots when a definition is selected.
- Manual invoice lines remain supported.
- No quantity on hand, warehouse/truck stock, receiving, purchasing, proposal builder, project BOM allocation, or accounting integration exists in Phase 8.

## Categories

`catalog_categories` belongs to one Organization. A Category may have one top-level parent, but a child cannot itself become a parent. Categories are made inactive rather than deleted. Category codes are unique within an Organization.

Suggested categories are intentionally not demo-seeded. Each Organization owns its operational taxonomy.

## Units of measure

`units_of_measure` belongs to one Organization and records a code, name, optional symbol, dimension, supported decimal precision, and active state. System seeding idempotently supplies each, foot, hour, visit, location, month, box, roll, bag, and case.

Unit roles are contextual:

- Base unit: smallest practical future consumption unit, such as foot.
- Purchase unit: how a Product is acquired, such as a 500-foot box.
- Sales unit: how an item is sold, such as visit, hour, or location.

A box or roll has no universal conversion. Checkpoint 2 will store Product-specific purchase-to-base quantities. No inventory balance is stored.

## Services

`catalog_services` stores organization/category ownership, a sales UOM, customer and internal descriptions, internal scope/exclusions, estimated duration, customer visibility, office-approval state, tax default, and active state.

Supported pricing models:

- `flat`: one price per sales unit.
- `hourly`: price per hour.
- `per_unit`: price per selected quantity.
- `variant`: requires an explicit active Variant; the Variant price overrides the optional Service fallback.
- `recurring`: a catalog definition with an amount, cadence, and interval.
- `quote_required`: known scope without a catalog price.

Recurring definitions do not enroll a Customer and do not charge a payment method. Subscription records remain Checkpoint 5 work requiring explicit approval.

Service Variants are explicit records rather than generalized pricing rules. The TV Mounting acceptance shape is supported with Up to 55-inch, 56–75-inch, and 76-inch-plus variants.

Service add-ons are simple related-Service suggestions. They never add themselves, create dependencies, or change price.

## Money and tax

Catalog prices use unsigned integer cents. Input strings are converted without floating-point arithmetic. Catalog Services carry a taxable boolean; later invoice selection will snapshot this boolean while the existing Invoice continues supplying the organization tax rate in basis points.

`CatalogPricingResolver` returns integer cents, requires a valid active Variant for variant pricing, and returns `null` for quote-required work. It does not calculate invoice discounts or tax; `InvoiceCalculator` remains canonical for financial totals.

## Organization scope and authorization

Every Catalog record contains `organization_id`. Controller resolution begins with the active Organization, nested identifiers are checked against their parent, and cross-organization identifiers return 404 with a safe rejected-access audit event.

Capabilities:

- `catalog.view`: read Catalog records.
- `catalog.use`: select Catalog records in later authorized workflows.
- `catalog.manage`: maintain descriptions, classification, variants, add-ons, UOMs, and active state.
- `catalog.pricing.manage`: change pricing model, prices, recurring cadence, and tax defaults.

Super Admin receives all capabilities. Dispatcher and Billing receive view/use, Technician receives field-oriented view/use for later integration, and Reviewer receives view. An explicit membership denial remains authoritative. Checkpoint 1 exposes Catalog management only in Office; field lookup arrives with Checkpoint 4.

## Audit and history

Create, update, deactivation, Variant, add-on, and rejected cross-organization actions use the existing `audit_events` infrastructure. Metadata contains record IDs, state/model names, and changed field names—not descriptions or price-input strings.

Catalog records have no hard-delete routes. Deactivation preserves relationships. Checkpoint 4 will snapshot catalog identity, description, unit price, tax behavior, selected Variant, and Package recipe so later catalog edits cannot change historical billing.

## Later checkpoints

- Checkpoint 2 extends this foundation with Products, base/sales units, and Product-specific purchase conversions.
- Checkpoint 3 adds Packages, standard recipes, optional waste, and the reusable Package demand calculator.
- Checkpoint 4 adds permission-aware field/Office selection and immutable invoice snapshots while retaining manual lines.
- Checkpoint 5 adds customer Service enrollment only after explicit approval. It will not add automatic recurring card charges without a separate approved payments design.

Future Inventory and Purchasing must extend Product base units, purchase conversions, and Package standard demand. They must add actual consumption separately and must never overwrite Package standard recipes.
