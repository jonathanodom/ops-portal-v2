# Phase 8.6 Checkpoint 6 Validation

## Scope

Checkpoint 6 makes Invoice Lines the visual center of the Office Invoice workspace without changing invoice calculation, provenance, authorization, payment, or immutability rules.

- Desktop invoices use a compact read-only item table with Description, Quantity, Unit, Rate, Tax, Amount, and an authorized Edit action.
- Smaller screens use compact item cards without horizontal overflow.
- Editing opens only the selected line in an accessible native dialog. Manual line creation uses a separate native dialog.
- Validation failures reopen the affected dialog, preserve submitted values, mark invalid controls, and focus the first invalid field.
- Catalog and manual entry remain explicit `+ Add Catalog Item` and `+ Add Manual Line` actions.
- Catalog snapshot provenance is collapsed under `Catalog source details`; Package recipes remain internal to the authorized Office workspace.
- Customer Note and Totals are paired below the item workspace.
- Approved Work & Billing Sources, Internal Billing Information, and organization-scoped Invoice Audit History are collapsed below totals.
- Existing payment controls were extracted to a view partial without behavioral changes. Payment overlays remain Checkpoint 7 work.

No migration, operational-data reset, capability change, route change, payment-domain change, or invoice-calculation change is included.

## Source and Changed Modules

- Starting commit: `742f58309149a2fa25551af1c1364bf765012df1`
- Branch: `codex/phase-8-6-connected-payments-billing-workspace`
- Invoice presentation: `resources/views/office/invoices/`
- Shared Catalog picker label support: `resources/views/components/catalog-picker.blade.php`
- Accessible item-dialog behavior: `resources/js/app.js`
- Responsive item-workspace styles: `resources/css/app.css`
- Scoped Invoice and Invoice Line audit projection: `app/Http/Controllers/Office/InvoiceController.php`
- Deterministic editable Beta Invoice fixture: `database/seeders/BetaScenarioSeeder.php`

## Validation

- Focused Invoice suite: 15 tests, 152 assertions — passed.
- Complete PHPUnit suite: 195 tests, 1,599 assertions — passed.
- Focused Playwright/axe Invoice workspace flow: 1 desktop project test — passed. The flow exercises the 1440px table, selected-line and manual-line dialogs, Escape close/focus return, the 390px card layout, full-viewport phone editor, overflow, and serious/critical axe checks.
- Composer validation: passed.
- Composer security audit: no vulnerability advisories.
- Pint: passed.
- Compiled Blade cache: passed.
- Vite production build: passed.
- Beta fixture validation: passed with 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, and 500 media metadata records; SQLite integrity passed.
- Diff check: passed.

## Preserved Boundaries

- Issued and void Invoice financial content remains immutable.
- Customer presentation and PDF output remain unchanged and do not expose Catalog recipes, internal notes, audit history, or source evidence.
- Invoice Line source identifiers, Visit/Closeout relationships, calculation snapshots, and Catalog snapshots remain unchanged.
- Organization scoping and the existing `invoices.manage` projection remain authoritative.
- Checkout, manual payment, refunds/reversals, receipts, and provider routing retain the accepted Phase 8.5/8.6 behavior.

Stop after Checkpoint 6. Record Payment and Pay Securely overlays, payment-history redesign, and related action routing belong to Checkpoint 7.
