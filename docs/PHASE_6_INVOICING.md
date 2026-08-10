# Phase 6 Invoicing and Billing Completion

## Scope and operational boundary

Phase 6 extends the FSM lifecycle from a ready `BillingHandoff` to an issued, customer-presentable invoice. An invoice represents the complete Service Ticket and associates every eligible approved closeout version across its nonarchived Visits. Service Ticket completion remains operational; invoice status remains financial. Payments, receipts, accounting synchronization, inventory mutation, and public customer access are excluded.

## Additive schema

- `organization_billing_settings`: organization seller/remit identity, USD currency, default terms, and explicit default tax rate.
- `billing_labor_rates`: named active hourly rates in cents with one application-enforced default.
- `invoices`: customer, service-address, seller, terms, tax, discount, totals, issue, PDF, void, and reissue snapshots.
- `invoice_closeouts`: exact approved closeout and review versions represented by the invoice.
- `invoice_lines`: scaled-integer quantities, integer-cent pricing/totals, source provenance, and safe override metadata.
- `invoice_acknowledgments`: append-only point-of-contact confirmation, presenter, timestamp, and idempotency token.
- `billing_handoffs.current_invoice_id`: the single current invoice across reissue generations.

The migration is additive and reversible. Rolling it back deletes Phase 6 invoices and configuration, so rollback is not an operational recovery procedure. Restore the verified pre-Phase-6 backup to a new database instead.

## Authorization matrix

| Capability | Super Admin | Billing | Reviewer | Dispatcher | Technician |
| --- | --- | --- | --- | --- | --- |
| `invoices.view` | Yes | Yes | Restricted summary | No | No |
| `invoices.manage` | Yes | Yes | No | No | No |
| `invoices.issue` | Yes | Yes | No | No | No |
| `invoices.present` | Yes | Yes | No | No | No |
| `invoices.discount` | Yes | No | No | No | No |
| `invoices.void` | Yes | No | No | No | No |
| `billing.settings.manage` | Yes | No | No | No | No |

Policies independently enforce active membership, organization scope, and capabilities. Presentation is an authenticated, shell-neutral route. Reviewer projection excludes price controls, internal notes, acknowledgments, and private documents.

## Calculation and provenance rules

- Invoice numbers are immutable `NDT-INV-YYYY-####`, organization/year scoped through the existing locked document sequence, and expand past four digits.
- Invoice creation locks and consumes one ready handoff. Refreshes, duplicate tokens, and concurrent retries return the handoff's current draft.
- Each approved Visit contributes one labor line. Effective reviewer-approved `on_site` plus `other` minutes are combined, rounded upward once to the next 15 minutes, and priced with the selected named rate.
- Travel remains visible in the billing packet and produces no automatic charge. Billing may add a reasoned manual travel line.
- Billable effective proposals generate source-linked lines with pricing required. Warranty, customer-owned, and no-charge proposals stay nonchargeable unless an authorized, reasoned invoice override adds a source-linked line. Technician evidence is never changed.
- Quantities use thousandths; money uses integer cents; rates use basis points. Line subtotal and tax use integer half-up division.
- A fixed or percentage invoice discount is capped at subtotal, allocated proportionally across positive included lines, and distributes remainder cents in stable line order. Tax applies after discount only to explicitly taxable lines.

Example: approved Visit durations of 61 and 16 minutes become 75 minutes and 30 minutes. At $120/hour, the labor lines are $150.00 and $60.00. A $1.00 invoice discount across $10.01 and $9.99 lines allocates exactly $0.51 and $0.49; an 8.25% rate applies only to each taxable post-discount base.

## Lifecycle, documents, and recovery

- `draft`: billing snapshot, lines, tax, terms, and authorized discount remain editable with safe audits.
- `ready_for_review`: final readiness checks have passed; Billing or Super Admin may issue.
- `issued`: all financial and source content is immutable. Customer HTML is immediately available.
- `void`: immutable history. Super Admin's reasoned action atomically creates a newly numbered draft linked to the void invoice and updates the handoff pointer without reopening the Service Ticket.

Issuance queues an idempotent after-commit PDF job. PDFs render from the issued snapshot, use an opaque UUID key on the private disk, and are served only by authorized controllers. Generation status is `pending`, `ready`, or `failed`; failures create a safe operational incident and expose an explicit retry. Customer acknowledgments are separate append-only portal events and are intentionally not injected into the issued PDF.

## Local preservation and validation

Before migration, the preserved Phase 5B SQLite database contained 1 organization, 1 user, 2 customers, 3 Service Tickets, 6 Visits, 5 closeouts, 3 reviews, 2 billing handoffs, and 15 migrations. A verified recovery point was created at `storage/app/backups/phase5b-before-phase6-20260808.sqlite` with SHA-256 `61126E9EA27FC608B44BC91658A46CC54CEA47BE17F39D36358C47A84A1FF37F`; isolated integrity and manifest comparison passed.

The additive migration completed against the preserved local SQLite database without replacing `.env`; operational row counts remained unchanged and the migration count advanced from 15 to 16. Docker is unavailable on this workstation, so the local `composer phase:update` sequence used its equivalent non-destructive Composer install, forced migration, idempotent capability seed, and frontend install/build steps. CI remains responsible for repeating migrations and the complete suite on MySQL 8.4.

Validation completed on August 8, 2026:

- PHPUnit: 95 tests passed with 701 assertions in 11.70 seconds.
- Compiled Blade lint: 111 generated PHP views passed syntax validation.
- Playwright/axe: 3 tests passed and 3 viewport-inapplicable tests skipped. Coverage included desktop billing/editor/health flows, 390×844 field/offline behavior, and phone-width invoice presentation with no horizontal overflow, sub-44px controls, or serious/critical axe violations.
- Pint, strict Composer validation, Composer security audit, Vite production build, and `git diff --check`: passed. Composer reported no known security advisories.
- Isolated beta setup retained its deterministic profile: 1 organization, 5 users, 250 customers, 400 locations, 500 Service Tickets, 1,000 Visits, 200 closeouts, 500 media records, and 3 scenarios.
- Manual browser verification confirmed the invoice-aware billing queue, desktop invoice editor, private presentation boundary, and customer-safe phone presentation without console errors or horizontal overflow.

The remaining owner gate is intentional: Jonathan must complete a realistic multi-Visit Service Ticket through invoice issue and confirm the phone presentation is understandable before merging or beginning Phase 7.

## Rollback and exclusions

Do not run destructive resets or replace `.env`. On migration failure, preserve the failed database and logs, verify the pre-Phase-6 backup again, restore it only to a new database, and validate before an explicitly approved environment switch.

Phase 6 does not deploy or add payment processing, receipts, raw card data, public invoice links, accounting integration, product catalog, inventory, automated jurisdiction tax, recurring billing, or general-ledger behavior.
