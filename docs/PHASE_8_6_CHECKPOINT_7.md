# Phase 8.6 Checkpoint 7 Validation

## Scope

Checkpoint 7 moves issued-Invoice payment actions into focused overlays while preserving the accepted payment, receipt, webhook, and provider-resolution workflows.

- **Record Payment** opens a responsive native dialog for cash/check amount, organization-local received time, required check reference, and an optional internal note.
- **Pay Securely** opens a responsive native dialog showing the resolved connected processor, balance, amount, and secure-link action. Ordinary collectors do not choose a processor.
- Super Admins may use an **Advanced** provider override only when an alternative provider is ready and the existing provider-lock rules allow switching.
- Office checkout creation returns to the Invoice and reopens the same workspace with QR, copy, open, refresh, and expire controls. The staff-assisted customer presentation route retains its direct hosted-checkout redirect.
- Current balance and payment state open a right-side Payment History drawer with transaction, receipt, refund/reversal, and follow-on collection actions.
- Validation failures reopen the affected overlay, preserve its submitted values, expose inline errors, and focus the first invalid field.
- Escape closes an overlay and restores focus to its launcher. Phone layouts use the full viewport; desktop layouts remain compact and scroll internally.

No migration, operational-data reset, capability addition, payment ledger change, webhook change, reconciliation change, receipt projection change, or invoice-calculation change is included.

## Source and Changed Modules

- Starting commit: `83746130beacec620e6a72120fc9366b28a3b3d5`
- Branch: `codex/phase-8-6-connected-payments-billing-workspace`
- Payment workspace: `resources/views/office/invoices/_payments.blade.php`
- Sticky Invoice actions: `resources/views/components/office/invoice-command-bar.blade.php`
- Accessible overlay behavior: `resources/js/app.js`
- Responsive overlay styles: `resources/css/app.css`
- Existing workflow delegation: `app/Http/Controllers/Office/PaymentController.php`, `app/Domain/PaymentWorkflow.php`
- Guarded provider override route: `routes/web.php`

## Focused Validation

- Payment and canonical provider/webhook suites: 17 tests, 122 assertions — passed.
- Complete PHPUnit regression: 198 tests, 1,637 assertions — passed.
- Focused Playwright/axe Invoice workspace flow: 1 desktop project test — passed. It covers Record Payment, Pay Securely, and Payment History overlays, Escape close/focus return, responsive Invoice behavior, and serious/critical axe checks.
- Pint (changed PHP files): passed.
- Compiled Blade cache: passed.
- Vite production build: passed.
- Isolated Beta setup: passed; all migrations and deterministic fixtures rebuilt only in `database/beta.sqlite`.
- Diff check: passed.

The complete browser matrix remains reserved for Checkpoint 10 as required by the Phase 8.6 plan.

## Preserved Boundaries

- Provider resolution remains organization default, authorized safe override, or sole ready provider; the normal Invoice editor has no processor selector.
- Active attempts and the first successful electronic payment retain the existing provider-switch locks.
- Only verified webhooks or authoritative reconciliation confirm electronic payment.
- Cash/check controls remain independent of provider configuration and retain the unresolved-attempt guard and required check-reference rule.
- Transactions, reversals/refunds, receipt records, public receipt privacy, hosted-link secrecy, and organization scoping are unchanged.
- Invoice financial immutability, Billing Handoff recovery, Visit provenance, and customer-safe presentation remain unchanged.

Stop after Checkpoint 7. Billing Queue and Invoice index separation belong to Checkpoint 8.
