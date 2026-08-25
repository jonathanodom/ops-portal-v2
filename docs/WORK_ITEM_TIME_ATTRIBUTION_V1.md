# Work Item Time Attribution V1

## Baseline and dependency

PR C branches from `main` at `040f3a7fdea391d0493e591061aacd50330bee04`, the merge commit for PR #44 **Add Service Ticket Work Items V1**. Its post-merge `core`, `safety`, `browser`, and aggregate `validate` checks passed before this branch was created.

## Three separate time concepts

- **Factual time** remains `VisitTimeEntry` plus PR A's immutable correction history. It answers when a person worked.
- **Captured work focus** is `visit_time_entries.service_ticket_work_item_id`. Null explicitly means Primary Service Ticket scope; an ID means the additional Ticket Work Item selected when the segment was captured.
- **Effective Office allocation** is the latest immutable `VisitTimeAllocationSet`. It explains where an ended factual interval should be attributed operationally without rewriting its timestamps or captured focus.

Travel is excluded. On-site and explicit/manual `other` time may carry Work Item focus or allocation.

## Natural Field segmentation

The automatic On Site transition still starts Primary-scope on-site time. A technician can start on-site time against an eligible Open or Needs-follow-up Work Item, or switch an active on-site timer between Primary scope and eligible Work Items.

Switching is one locked transaction. One boundary timestamp ends the old entry and starts the next entry, preventing gaps and overlaps. Selecting the current target is an idempotent no-op. Work Item selection uses PR B's canonical Visit-touch provenance seam.

An active attributed timer prevents that Work Item from changing to Completed, Needs follow-up, Transferred, or Canceled. The user must stop it or switch focus first; Office cannot silently retarget another person's timer.

## Immutable Office allocation

`visit_time_allocation_sets` are append-only, sequenced per factual entry, actor-attributed, and reasoned. Child `visit_time_allocations` store integer seconds. A null child Work Item is an explicit Primary-scope row. The highest sequence is current; earlier sequences remain visible.

Partial allocation is valid. The factual duration minus the latest allocation total is reported as **Unallocated** and is never silently assigned to Primary scope. Without an allocation set, the full effective duration follows captured focus.

Only an active membership with the exact `super_admin` role and effective `visit_time.allocate_work` capability may allocate. Explicit capability denial remains authoritative. Ended on-site and other entries can be allocated through draft, submitted, returned, resubmitted, approved, completed, Billing Handoff, and Invoice lifecycle states. Historical terminal Work Items remain valid targets. A submitted/later Closeout cannot allocate to an Open Work Item until Office dispositions it.

## Correction conservation

Both ordinary draft correction and PR A submitted factual correction enforce:

```text
latest allocated seconds <= proposed effective factual duration
```

Shrinking below allocated time is rejected with “Reduce the current time allocation before shortening this factual interval.” No automatic trimming or proportional reallocation occurs. Increasing factual duration preserves the allocation and exposes the added duration as Unallocated.

## Operational projection and UI

Field Time shows captured focus and mobile work-focus switching separately from PR B's Work lifecycle controls. Office Ticket execution and Closeout Review show factual intervals, captured focus, current allocation, Unallocated remainder, and immutable history. A Ticket rollup groups corrected effective on-site/other seconds across Primary scope, each Work Item, and Unallocated. Transferred Work Item history stays on the originating Ticket and Visit.

## Billing boundary

Allocation is operational only. It does not change Closeout review adjustments, approved minutes, trip-charge recommendations, Service Ticket completion totals, Billing Handoffs, Invoice lines/totals, rates, costs, or payment state. Customer-visible labor and per-Work-Item commercial behavior remain outside PR C.

## Purge, archive, and audit

The guarded Ticket purge deletes allocation rows, allocation sets, factual entries, Work Item provenance, then the remaining Visit/Ticket graph with foreign keys enabled. Visit permanent deletion already rejects any Visit with factual time, which also protects direct focus and allocation history.

Safe audits record Work Item IDs, Visit/entry IDs, allocation sequence, integer seconds, and Unallocated seconds. Allocation reasons, Work Item details/notes, and customer narrative are excluded from audit metadata.

## Validation

Local validation on August 25, 2026:

- Focused PR C suite: 8 tests, 25 assertions. The wider time, Work Item, Closeout, Billing, purge, and archive matrix passed 79 tests with 708 assertions before the final two focused authorization/Billing regressions were added.
- Full PHPUnit: 409 tests, 3,340 assertions in 59.69 seconds. The destructive field-test purge flag was explicitly disabled for the normal retained-database test run.
- Fresh isolated beta setup applied every migration, including rollback/reapply of the two PR C migrations. Exact fixture validation passed for 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, 500 media records, and the existing Projects/scenario fixtures.
- Ten-run beta benchmark passed every budget without raising a query ceiling: Office Dashboard 31 queries, Today 9, Dispatch 15, Projects 16, Customer Detail 21, Project Detail 25, Service Ticket Detail 27, Closeout Review Detail 27, and private media 0.1 ms locally.
- Authenticated Playwright/axe: 27 passed and 23 intentionally skipped by project/optional screenshot guards in 2.4 minutes. The Work Item Field workspace was exercised at 390, 768, 1280, 1440, and 1920 pixels with no serious/critical axe findings or horizontal overflow.
- Composer strict validation and locked-package audit passed. Pint, 181 compiled-Blade PHP syntax checks, Vite production build, and `git diff --check` passed. Production assets measured 103.09 kB CSS and 70.89 kB JavaScript before compression.
- A recoverable retained SQLite backup was verified at `storage/app/backups/work-item-time-attribution-v1.sqlite`, SHA-256 `C86966EB6559C5125FC8DE619ED26C02072C2C386D97D2420E4D22F092637D27`. It was created after the additive migration rather than before it; isolated restore integrity, migration state, table counts, relationships, and representative workflows matched. No retained operational records or `.env` values were reset.
- MySQL 8.4 migration and regression validation is delegated to the draft PR's required GitHub `core` job; the draft is not ready to merge unless aggregate `validate` is green.

## Future PR D boundary

Future commercial work may consume this reusable attribution projection, but must independently define per-Work-Item billing policy. PR C intentionally adds no invoice generation, rates, profitability, Project aggregation UI, Staff Time Tracking, payroll, travel allocation, or cross-Ticket labor transfer.
