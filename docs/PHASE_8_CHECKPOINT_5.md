# Phase 8 Checkpoint 5 Validation

## Scope

Checkpoint 5 implements Phase 8F Recurring Customer Services Foundation after explicit approval:

- Separate Customer enrollment records for active recurring Catalog Services.
- Optional active Service Location and optional active Service Variant selection.
- Immutable commercial snapshots for identity, Variant, UOM, cadence, amount, and tax default.
- Active, paused, and terminal canceled lifecycle.
- Reasoned amount overrides and safe audit events.
- Organization-wide Customer Services queue plus Customer-detail enrollment history.

It adds no automatic Invoice generation, Billing Handoff, Service Ticket, Visit, Square/Stripe subscription, electronic charge, saved payment method, payment retry, notification, inventory, purchasing, or accounting integration.

## Schema and invariants

Migration `2026_08_12_020000_create_customer_service_enrollments_table` adds one organization-owned table. Existing migrations are unchanged.

Every enrollment stores:

- Customer and optional Service Location.
- Nullable Catalog Service and Variant references for current navigation.
- Immutable Service code/name/customer description, Variant code/label, UOM, cadence/interval, amount, and taxable snapshots.
- Start, optional end, and optional next-billing dates.
- Status actors/timestamps, cancellation actors/timestamps, creator/updater, and internal notes.
- A nullable unique `current_scope_key` generated from Organization, Customer, location scope, Service, and Variant.

The unique key allows only one active or paused enrollment for an exact scope, including under concurrent requests. Canceling clears it, preserves the record, and permits a later new enrollment. Canceled enrollments cannot be edited or resumed.

Only active recurring Catalog Services with complete cadence and interval may be selected. Locations must be active and belong to the same Customer and Organization. Variants must be active and belong to the selected Service and Organization. Current enrollments block Customer or selected-location archival.

## Authorization

| Role | Default access |
|---|---|
| Super Admin | View and manage |
| Dispatcher | View and manage |
| Reviewer | Read only |
| Billing | Read only |
| Technician | None |

`subscriptions.view` and `subscriptions.manage` are enforced independently of Catalog and Customer permissions. Explicit capability overrides, inactive memberships, active Organization resolution, and organization-scoped record lookup remain authoritative.

## Audit and privacy

Create, update, amount override, accepted status changes, rejected status changes, and cross-organization attempts reuse `audit_events`. Metadata contains enrollment, Customer, location, Catalog source, state, and changed-field identifiers. It excludes internal notes, customer contact details, Service descriptions, and amount-override reason contents.

## Automation boundary

`next_billing_date` is planning data only. Enrollment writes never create or mutate:

- Invoices or Invoice Lines.
- Billing Handoffs.
- Payment Attempts, Transactions, or Receipts.
- Square or Stripe objects.
- Service Tickets or Visits.

Existing issued Invoices and payment history are independent. Canceling an enrollment does not refund or void them.

## Local preservation

Before migration:

- Backup: untracked `storage/app/backups/phase8-checkpoint5-pre-migration.sqlite`
- SHA-256: `0c7d861ddacca1e0f1bfb2c99516864823024e81aa94ee7bf17233137455c877`
- Restore verification: SQLite integrity `ok`; 56 tables; migrations, counts, relationships, and representative workflows matched.

The additive migration and idempotent Access/Catalog seed completed. Counts remained 1 Organization, 1 User, 4 Customers, 5 Service Locations, 4 Service Tickets, 7 Visits, 6 Closeouts, 3 Billing Handoffs, 2 Invoices, 3 Invoice Lines, 1 Catalog Service, 0 Products, and 0 Packages. The new enrollment table began empty. `.env` was not replaced.

The aggregate `composer phase:update` wrapper reached its five-minute nested Composer timeout before migration. The equivalent documented component operations then completed directly: migration, idempotent seed, lockfile dependency restore, and Vite production build. A running local Vite process briefly locked a native CSS module during `npm ci`; only that repository-scoped Vite process was stopped, dependencies were restored, the build passed, and Vite was restarted.

## Automated validation

- Recurring enrollment feature tests: 6 passed, 79 assertions.
- Focused Catalog/Customer regression: 28 passed, 269 assertions.
- Complete PHPUnit suite: 155 passed, 1,306 assertions.
- Beta fixtures: exact deterministic profile passed (250 Customers, 400 Service Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, and 500 private-media metadata records); SQLite integrity `ok`.
- Beta validation: 8 passed, 50 assertions.
- Local warm benchmark (20 runs): Today p95 11.1 ms / 18 max queries; Dispatch p95 23.0 ms / 16; Service Ticket detail p95 20.3 ms / 21; review detail p95 20.8 ms / 26; private-media first byte p95 0.2 ms.
- Playwright Chromium/axe: 8 applicable scenarios passed and 8 opposite-project scenarios skipped as designed. The empty Customer Services workspace passed desktop overflow and serious/critical axe checks.
- Composer validation and audit: passed; no known security advisories.
- Pint: passed.
- Compiled Blade syntax lint: 153 files passed.
- Vite production build: passed (56 modules transformed).
- Diff and repository hygiene checks: passed; no `.env`, beta database, backup, or browser artifact is tracked.

## Rollback

The migration `down()` removes only `customer_service_enrollments`. It does not alter Catalog Services, Customers, locations, operational FSM records, Invoices, Payments, or provider settings. Rollback after enrollment creation intentionally removes enrollment history; use the verified backup when that history must be retained.

## Phase boundary

Checkpoint 5 concludes the approved Phase 8 plan. Recurring Invoice generation, automatic payment charging, customer portals, notifications, inventory, purchasing, proposal/estimate workflows, and accounting synchronization require separate planning and approval.
