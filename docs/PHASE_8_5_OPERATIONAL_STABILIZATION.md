# Phase 8.5 Operational Stabilization

## Baseline and scope

- Branch: `codex/phase-8-5-operational-stabilization`
- Authoritative baseline: `6e1a83e2b7dde1c948587f4ef1cc8070fc072baf`
- Baseline validation: 155 PHPUnit tests and 1,306 assertions; GitHub Actions run 31632195143 passed.
- Delivery is additive. Existing operational migrations remain unchanged and `.env` was preserved.

Phase 8.5 decouples invoice issuance from hosted payment readiness, adds guarded deletion of unissued invoices, and introduces immutable ticket-relative Visit identity. It does not change issued/void Invoice history, payment ledger arithmetic, operational provenance foreign keys, or Phase 8 Catalog behavior.

## Checkpoint 1: payment and invoice lifecycle

Positive and zero-dollar Invoices can move to Ready for Review and Issue without Square or Stripe. `preferred_payment_provider` remains optional and may be selected or cleared until the first electronic attempt locks the Invoice. Square/Stripe configuration readiness, balance, unresolved-attempt, and provider-lock enforcement remain in `PaymentWorkflow::createCheckout()`.

Cash and check collection remains available independently of a hosted provider. Issued Office and customer-presentation screens clearly show “No hosted payment provider / Select later,” render post-issue selection only to authorized collectors, and show checkout creation only for a selected ready provider.

Focused Checkpoint 1 validation passed: 17 tests and 123 assertions. Coverage includes provider-free issue, zero-dollar issue, cash/check receipts, missing-provider checkout rejection, post-issue Square selection, and first-attempt locking. Square and Stripe lifecycle coverage uses deterministic test adapters and signed webhook fixtures; no live transaction was performed.

## Checkpoint 2: delete unissued Invoice

Capability `invoices.delete_draft` is seeded only through Super Admin’s existing all-capabilities behavior. `InvoicePolicy::deleteDraft` and the organization-scoped Office delete endpoint independently enforce authorization.

Deletion requires a reason, exact Invoice-number confirmation, and explicit confirmation. The row-locked workflow permits only `draft` and `ready_for_review` Invoices with no issue, void, reissue, PDF, acknowledgment, payment-attempt, transaction, receipt, provider-lock, parent, or replacement history. It restores the Billing Handoff to `ready`, clears handed-off metadata, removes only draft Invoice Lines and closeout links, and preserves tickets, Visits, closeouts, reviews, evidence, and provenance.

The durable `invoice.unissued_deleted` audit event is attached to the surviving Billing Handoff and records the deleted Invoice identity, ticket, customer, handoff, prior status, total, actor, and required reason. The deleted draft’s allocated document number is never reused.

Focused Checkpoint 2 validation passed: 11 tests and 95 assertions. It covers draft/ready eligibility, issued/void/reissue guards, payment dependencies, capability denial, cross-organization 404 behavior, durable audit history, and recreation from the restored handoff.

## Checkpoint 3: ticket-relative Visit identity

Migration `2026_08_12_030000_add_ticket_relative_visit_numbers` adds:

- `service_tickets.next_visit_number`, required unsigned integer, default 1.
- `visits.ticket_visit_number`, required unsigned integer.
- Unique `(service_ticket_id, ticket_visit_number)` constraint.

Backfill orders all active and soft-deleted Visits by `created_at ASC, id ASC`, then advances each ticket counter beyond its historical maximum. Ticket-relative numbers are therefore independent per ticket and cannot be reused after cancellation, archive, soft deletion, or permanent purge.

`VisitCreator` row-locks the Service Ticket and consumes its counter for initial, dispatcher-added, return-trip, field-outcome, reviewer-disposition, and beta-fixture creation. A model-level safety hook covers legacy/test creation paths. Presentation uses `Visit 2` and `Visit 2 · Return of Visit 1`; route keys, database foreign keys, audit identifiers, and destructive confirmation IDs remain internal database IDs.

New labor descriptions are customer-safe: `Service Labor — {ticket title}` with the Visit-local service date appended when multiple scheduled approved Visits need differentiation. Exact legacy generated descriptions are refreshed when an editable Invoice moves to Ready for Review and again before Issue. Manually edited source lines containing their known raw Visit database ID are blocked from Issue. Issued and void Invoices remain immutable.

### Retained local data preservation

- Backup: untracked `storage/app/backups/ops-20260812-222305.sqlite`
- SHA-256: `5d2fb424edfe96bced56452531b12c62d31a9cb507592d27775dbddbd8dc56f8`
- Isolated restore verification: SQLite integrity `ok`; migrations, table counts, relationships, and representative workflows matched.
- The migration was applied without reset. Retained counts remained 1 Organization, 1 User, 4 Customers, 5 Service Locations, 4 Service Tickets, 7 Visits, 6 Closeouts, 3 Billing Handoffs, and 2 Invoices.
- Historical backfill produced per-ticket counters above every active and archived Visit. All four retained tickets and all seven retained Visits received valid numbers.
- `.env` was not replaced.

The aggregate `composer phase:update` wrapper reached its nested dependency wait timeout before migration on this Docker-unavailable workstation. Its documented additive operations were completed directly: migration, idempotent capability seed, dependency restore, and production build. A repository-scoped Vite process briefly held a native CSS module open; only that process was stopped, `npm ci` succeeded, and the build passed.

Focused Checkpoint 3 validation passed: 52 workflow tests and 409 assertions, followed by 24 numbering/invoicing tests and 176 assertions after the final acceptance cases were added.

## Authorization and lifecycle matrix

| Action | Default authority | Guardrails |
| --- | --- | --- |
| Issue without hosted provider | Billing, Super Admin | Existing Invoice issue requirements still apply. |
| Select/clear hosted provider | Authorized collector | Only before first electronic attempt; checkout still requires readiness. |
| Record cash/check | Billing, Super Admin | Issued Invoice, no unresolved electronic attempt, check reference required. |
| Delete unissued Invoice | Super Admin | Draft/ready only, exact number, reason, confirmation, and no protected dependencies. |
| View Visit business identity | Existing authorized Office/Field roles | Ticket-relative label only; organization scope unchanged. |
| Create Visit | Existing dispatch/execution workflows | Transactional ticket counter; no cross-organization inputs accepted. |

Explicit membership grants and denials and inactive-membership restrictions remain authoritative.

## Full validation

- Complete PHPUnit: 163 tests, 1,354 assertions passed.
- Composer strict validation: passed.
- Composer audit: passed; no known security advisories.
- Pint: passed after formatting; final `--test` passed.
- Compiled Blade syntax: 153 files passed.
- Vite production build: passed; 56 modules transformed.
- Beta fixture: exact deterministic profile passed with 250 Customers, 400 locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, and 500 private-media metadata rows; SQLite integrity `ok`.
- Beta hardening: 8 tests, 50 assertions passed.
- Warm local benchmark (10 runs): Today p95 12.0 ms / 18 max queries; Dispatch 15.0 ms / 16; Service Ticket detail 16.6 ms / 21; Review detail 12.5 ms / 26; private-media first byte 0.2 ms.
- Playwright Chromium/axe: 8 applicable desktop/mobile scenarios passed; 8 opposite-device scenarios skipped as designed; no serious or critical axe violations.
- Backup verification: retained pre-migration backup passed isolated integrity, relationship, count, and representative-workflow comparison.
- Migration rollback rehearsal: `down()` and re-apply passed on the isolated beta database. Browser-created beta records were then removed with guarded `beta:setup`, and the deterministic fixture passed again.
- `git diff --check`: passed.

The complete automated scenarios exercise provider-free Issue, cash and check, partial electronic payment, Square/Stripe adapter checkout, provider locking, webhook reconciliation, receipts, reversals/refunds, return Visits, multiple-Visit invoicing, draft deletion, and handoff recreation. No live provider transaction was authorized or attempted.

## Rollback and remaining gate

The migration `down()` removes the unique Visit-number constraint and the two numbering columns only. Rolling back after new Visits exist discards their ticket-relative business numbers; use the verified backup when that identity must be retained. Checkpoints 1 and 2 require application rollback rather than data rollback; deleted draft Invoices are intentionally not recoverable from the active database, but their durable handoff audit record remains.

GitHub Actions status is recorded in the draft PR after push. Phase 9 remains blocked until CI is green and Jonathan manually approves realistic cash/check, Square/Stripe sandbox, multi-Visit, and delete/recreate scenario validation.
