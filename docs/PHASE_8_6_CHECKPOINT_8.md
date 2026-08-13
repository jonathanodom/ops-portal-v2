# Phase 8.6 Checkpoint 8 Validation

## Scope

Checkpoint 8 separates the operational Billing Handoff queue from a real organization-scoped Invoice ledger.

- **Queue** retains approved-work handoffs and their ready-to-invoice or invoice-started workflow.
- **Invoices** adds `/office/invoices` as a searchable, sortable, paginated ledger. Invoices still originate only from approved Billing Handoffs.
- The desktop ledger uses a dense table with Date, Invoice Number, Customer, Ticket / Project, Status, Due Date, Total, and Balance.
- Phone and tablet layouts use compact Invoice cards with no horizontal overflow.
- Filters cover Invoice number, Customer, Ticket / Project, Invoice status, payment state, open/paid/overdue balance, and date range. Sorting covers every business column that has a meaningful deterministic order.
- Invoice detail and summary back-navigation now returns to the Invoice ledger.
- Memberships with Invoice summary access but no Billing Handoff access can enter the Invoice ledger without gaining queue or Invoice-management authority.

No migration, operational-data reset, Invoice-creation shortcut, accounting widget, authorization expansion, calculation change, payment-domain change, webhook change, Billing Handoff recovery change, or provenance change is included.

## Source and Changed Modules

- Starting commit: `db5669ded54cd955061eaf3b15744b94c4702306`
- Branch: `codex/phase-8-6-connected-payments-billing-workspace`
- Invoice ledger query and filters: `app/Http/Controllers/Office/InvoiceController.php`
- Invoice routes: `routes/web.php`
- Billing workspace navigation: `resources/views/components/office/billing-workspace-tabs.blade.php`
- Queue presentation: `resources/views/office/billing-handoffs/index.blade.php`
- Invoice ledger presentation: `resources/views/office/invoices/index.blade.php`
- Office navigation and Invoice back-navigation: `resources/views/components/layouts/office.blade.php`, `resources/views/components/office/invoice-command-bar.blade.php`, `resources/views/office/invoices/summary.blade.php`
- Dense responsive conventions: `resources/css/app.css`

## Focused Validation

- Complete Phase 6 Invoice suite: 17 tests, 203 assertions — passed.
- Complete PHPUnit regression: 200 tests, 1,688 assertions — passed.
- Focused Playwright/axe Billing workspace flow: 1 desktop project test — passed. It covers Queue/Invoices navigation, Invoice filtering, desktop table and 390px card switching, overflow, Invoice row navigation, and serious/critical axe checks.
- Composer validation: passed.
- Composer security audit: no vulnerability advisories.
- Pint: passed.
- Compiled Blade cache: passed.
- Vite production build: passed.
- Isolated Beta setup: passed; all migrations and deterministic fixtures rebuilt only in `database/beta.sqlite`.
- Diff check: passed.

The complete browser matrix remains reserved for Checkpoint 10.

## Preserved Boundaries

- Billing Handoff remains the only source of new Invoices; `/office/invoices` has no arbitrary create action.
- Invoice financial immutability, calculation snapshots, approved Closeout associations, Visit provenance, PDF history, and reissue lineage are unchanged.
- Billing Handoff deletion recovery and its current-Invoice relationship are unchanged.
- Payment ledger math, provider resolution, checkout reconciliation, cash/check collection, refunds/reversals, and receipts are unchanged.
- Every Invoice query remains scoped to the active organization and the existing `invoices.view` policy/capability.
- Reviewer access remains a restricted Invoice summary projection and does not grant Billing queue or Invoice-management access.

Stop after Checkpoint 8. Minimal Field Beta UX polish belongs to Checkpoint 9.
