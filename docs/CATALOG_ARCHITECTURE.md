# Products & Services Catalog Architecture

## Checkpoint status

Phase 8 Checkpoints 1 through 4 implement the organization-scoped Catalog foundation, Services, Products, Product-specific purchase-unit conversions, Packages with standard Product/Service recipes, permission-aware field selection, invoice selection, and immutable transactional snapshots. Customer Service enrollment remains unimplemented pending explicit Checkpoint 5 approval.

## Catalog boundaries

- A Service describes labor or a standardized customer outcome.
- A Product describes a physical item with a base consumption unit, a contextual sales unit, and Product-specific purchase conversions.
- A Package describes one customer-facing sale with an internal Product/Service recipe.
- A catalog definition is a current default. Field proposals and Invoice Lines retain immutable source snapshots when a definition is selected.
- Manual invoice lines remain supported.
- No quantity on hand, warehouse/truck stock, receiving, purchasing, proposal builder, project BOM allocation, or accounting integration exists in Phase 8.

## Categories

`catalog_categories` belongs to one Organization and includes an optional operational description. A Category may have one top-level parent, but a child cannot itself become a parent. Categories are made inactive rather than deleted. Category codes are unique within an Organization.

Suggested categories are intentionally not demo-seeded. Each Organization owns its operational taxonomy.

## Units of measure

`units_of_measure` belongs to one Organization and records a code, name, optional symbol, dimension, supported decimal precision, and active state. System seeding idempotently supplies each, foot, hour, visit, location, month, box, roll, bag, and case.

Unit roles are contextual:

- Base unit: smallest practical future consumption unit, such as foot.
- Purchase unit: how a Product is acquired, such as a 500-foot box.
- Sales unit: how an item is sold, such as visit, hour, or location.

A box or roll has no universal conversion. Product purchase options store Product-specific purchase-to-base quantities. No inventory balance is stored.

## Products and purchase units

`catalog_products` stores organization/category ownership, product code and optional SKU, manufacturer/model identity, separate customer/internal descriptions, base and default sales UOMs, fixed-point sales quantity, integer-cent cost/sell defaults, tax default, future tracking classification, and active state.

Quantities use thousandths (`1000` = one whole unit). For wire sold and consumed by the foot, the base UOM and sales UOM are both Foot and the sales quantity is `1000`. A cost may cover a larger fixed-point base quantity, avoiding a floating-point or prematurely rounded per-foot cost.

`catalog_product_purchase_units` records the Product, purchase UOM, label, exact base quantity in thousandths, optional vendor SKU, optional integer-cent pack cost, default flag, and active state. Exactly zero or one active option may be default. The default switch is applied transactionally with row locks. Examples:

- 250-foot Cat6 box: `base_quantity_millis = 250000`
- 500-foot Cat6 box: `base_quantity_millis = 500000`
- 1,000-foot Cat6 box: `base_quantity_millis = 1000000`

`CatalogProductConversion` uses checked integer arithmetic and deterministic half-up rounding for fractional purchase quantities. It never reads or writes inventory balances.

The Product `tracking_type` (`standard`, `serialized`, or `lot_or_roll`) is classification metadata for later inventory design. It does not enable quantity on hand, stock movements, serial records, receiving, or warehouse/truck locations.

## Packages and standard recipes

`catalog_packages` stores organization/category ownership, Package code and name, separate customer/internal descriptions, sales UOM, flat or quote-required pricing, integer-cent default price, tax default, and active state. A Package is a sellable definition; it is not a stock item and cannot contain another Package in Checkpoint 3.

`catalog_package_components` stores exactly one Product or Service source, its explicit recipe UOM, standard fixed-point quantity per one Package sales unit, optional Product waste in basis points, customer-visibility flag, ordering, internal notes, and active state. Product quantities may be entered directly or defined as a fixed-point pull count multiplied by a fixed-point standard allowance per pull. The resolved `quantity_millis` is persisted with the optional basis fields so forecasting has a direct quantity while the recipe still explains how that quantity was derived. Service components always use direct quantity. Product components use the Product base UOM; Service components use the Service sales UOM. Source and UOM IDs remain explicit so a later source edit cannot silently rewrite the current recipe.

Recipe quantity is the expected estimating standard. Actual usage is a separate future execution record and must never overwrite `quantity_millis`. Deactivating a component removes it from current demand without deleting the recipe history.

`PackageDemandCalculator` scales active components using checked integer arithmetic and deterministic half-up rounding. It returns Product standard demand, Product planning demand after component-specific waste, and Service demand. `CatalogLineSnapshotFactory` uses that result when a Package is selected so the customer transaction remains one Package line while the internal recipe and expected demand become immutable transaction metadata. Neither service creates inventory transactions, job-cost records, or actual-consumption records.

Integrated Smart Home TV Rough-In is represented as one Package sold per Location. Its recipe retains a 175-foot standard allowance per pull: two Blue Cat6 pulls, two Yellow Cat6 pulls, one 16/2 speaker-wire pull, and one 16/4 speaker-wire pull. The resolved per-location Product quantities are 350 ft, 350 ft, 175 ft, and 175 ft. Quantity five therefore produces standard demand of 1,750 ft, 1,750 ft, 875 ft, and 875 ft respectively while the future customer transaction remains five Package units.

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

Catalog prices use unsigned integer cents. Input strings are converted without floating-point arithmetic. Catalog Services, Products, and Packages carry a taxable boolean; later invoice selection will snapshot this boolean while the existing Invoice continues supplying the organization tax rate in basis points.

`CatalogPricingResolver` returns integer cents, requires a valid active Variant for variant pricing, and returns `null` for quote-required work. `CatalogLineSnapshotFactory` copies that result, source identity, customer description, UOM, tax default, selected Variant, and optional Package recipe into field/invoice snapshot columns. It does not calculate invoice discounts or tax; `InvoiceCalculator` remains canonical for financial totals.

## Field and Invoice integration

The field closeout workspace and editable Office Invoice use the same responsive Catalog picker. It is search-first, supports explicit Service Variants and fixed-point quantity, and is available only with `catalog.use` in an already-authorized workflow.

Field selection extends `visit_part_proposals`, which already participates in closeout correction lineage, reviewer treatment/quantity adjustments, and ticket-wide invoice generation. Catalog-selected proposals add immutable typed snapshot columns; custom proposals retain their existing nullable Catalog fields. Technicians never receive price inputs or Catalog price-management authority. The selected price is retained server-side for Billing review.

Editable Invoice Lines can be added directly from the Catalog or generated from approved field proposals. Both retain nullable source foreign keys plus immutable code, name, customer description, UOM, selected quantity, original/effective selected price, tax behavior, selected Variant, selection actor/time, and Package recipe/demand. Invoice Line description, effective quantity, price, inclusion, and treatment remain invoice-owned transactional fields. Reasoned Billing edits never rewrite the source snapshot.

Manual Invoice Lines remain fully supported and have null Catalog source/snapshot fields. Inactive or later-edited Catalog definitions do not change existing proposals, invoices, void/reissue copies, issued HTML, or PDFs. Customer presentation renders the Package line only; it never exposes the internal recipe snapshot.

## Organization scope and authorization

Every Catalog record contains `organization_id`. Controller resolution begins with the active Organization, nested identifiers are checked against their parent, and cross-organization identifiers return 404 with a safe rejected-access audit event.

Capabilities:

- `catalog.view`: read Catalog records.
- `catalog.use`: select Catalog records in later authorized workflows.
- `catalog.manage`: maintain descriptions, classification, variants, add-ons, UOMs, and active state.
- `catalog.pricing.manage`: change pricing model, prices, recurring cadence, and tax defaults.

Super Admin receives all capabilities. Dispatcher and Billing receive view/use, Technician receives field-oriented view/use, and Reviewer receives view. An explicit membership denial remains authoritative. `catalog.use` never grants visit execution or Invoice management by itself; the surrounding workflow policy remains independently required.

## Audit and history

Create, update, deactivation, Variant, add-on, and rejected cross-organization actions use the existing `audit_events` infrastructure. Metadata contains record IDs, state/model names, and changed field names—not descriptions or price-input strings.

Catalog records have no hard-delete routes. Product, purchase-option, Package, and recipe-component deactivation preserves relationships and standard history. Transaction snapshots preserve catalog identity, description, unit price, tax behavior, selected Variant, and Package recipe so later Catalog edits cannot change historical billing.

## Later checkpoints

- Checkpoint 2 extends this foundation with Products, base/sales units, protected cost/sell defaults, and Product-specific purchase conversions.
- Checkpoint 3 adds Packages, standard recipes, optional waste, and the reusable Package demand calculator.
- Checkpoint 4 adds permission-aware field/Office selection and immutable invoice snapshots while retaining manual lines.
- Checkpoint 5 adds customer Service enrollment only after explicit approval. It will not add automatic recurring card charges without a separate approved payments design.

Future Inventory and Purchasing must extend Product base units, purchase conversions, and Package standard demand. They must add actual consumption separately and must never overwrite Package standard recipes.
