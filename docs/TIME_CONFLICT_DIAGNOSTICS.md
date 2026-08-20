# Time Conflict Diagnostics

## Baseline and scope

- Baseline: `de57aae1665f07dc1ca6f38c593a39f5a5dd6422` on `main`.
- Delivery branch: `feat/time-conflict-diagnostics`.
- This change improves existing Visit time-entry validation only. It adds no migration, time category, capability, or time-tracking workspace.

## Field-review problem

Manual time backfills and corrections previously returned only `That time overlaps another entry for this user.` That message did not identify whether the conflict belonged to the current Visit, another Visit or Ticket, or an abandoned active timer.

The validator now locks and retrieves the first deterministic conflicting `VisitTimeEntry` and reports safe operational context:

- staff display name;
- friendly source and category labels;
- the local start/end interval, or an explicit active/no-end state;
- Service Ticket number; and
- ticket-relative Visit number.

No internal note or correction-reason content is included. Validation messages remain plain text.

## Unchanged overlap semantics

For the same user, a proposed interval conflicts when:

```text
existing.started_at < proposed.end
AND
(existing.ended_at IS NULL OR existing.ended_at > proposed.start)
```

The lookup remains global per user to preserve established behavior. Adjacent entries such as `08:00–09:00` and `09:00–10:00` remain valid. Actual intersections remain invalid. Corrections continue excluding their own entry ID. Locking remains in the same transaction, and no entry is automatically stopped, shortened, split, or corrected.

## Active timers

An entry without `ended_at` is identified as active and as having no end time. The diagnostic includes its start time and safe Ticket/Visit context when it belongs to the active Organization. The workflow never modifies that timer.

## Timezone

The displayed interval uses the conflicting Visit timezone. If that is missing or invalid, it falls back to the conflicting Ticket's Organization timezone, then the application timezone. Raw UTC is never shown to the user.

## Tenant and privacy behavior

Same-Organization conflicts include the Ticket and Visit identifiers needed to find the blocking record. Cross-Organization overlap enforcement is intentionally retained, but its validation message is generic and never includes the other Organization's Ticket number, Visit number, timezone, or other operational context.

Only the existing `VisitTimeEntry → Visit → ServiceTicket` relationships are used. Ticket and Visit numbers are not denormalized. Soft-deleted Visits remain resolvable for diagnostics, and unavailable relationship data falls back to safe labels instead of causing an exception.

## Office and Field behavior

- Office manual-entry and correction requests continue using existing `visits.execute_any`, Visit policy, crew-owner, and Organization checks.
- The Office execution dialog reopens through the existing `execution_visit` URL behavior after validation failure.
- Office manual-entry and correction values are retained after failure; the affected correction disclosure reopens.
- Field technicians retain their existing own-entry correction restriction. Their form values are retained, the affected disclosure reopens, and no Office-only link is rendered.
- No conflict deep link was added because the detailed plain-text diagnostic and existing execution dialog behavior solve the field-review problem without adding cross-surface session state.

## Validation

Focused implementation validation:

- `TimeConflictDiagnosticsTest`, `OfficeExecutionParityTest`, and `MobileFieldExecutionTest`: 29 tests, 252 assertions passed.
- Pint check: passed.
- `git diff --check`: passed.

- Full PHPUnit: 374 tests and 3,064 assertions passed with the destructive field-test purge flag set to its documented default-off value. The developer's local `.env` was not changed.
- Composer validation: passed.
- Composer audit: no security advisories.
- Pint: passed.
- Compiled Blade syntax: 177 templates passed.
- Vite production build: passed.
- Isolated beta fixture: exact expected counts and integrity checks passed.
- Beta benchmark: all query ceilings and response-time budgets passed; highest measured p95 was 61.2 ms for Dispatch.
- Playwright/axe: 22 applicable workflows passed and 18 intentional project/viewport skips completed across desktop and 390px projects, with no serious or critical accessibility violations.
- `git diff --check`: passed.

## Deferred work

Staff Time Tracking V1 remains separate future work. This change does not add staff totals, timesheets, payroll, overtime, PTO, wage rates, exports, Project labor dashboards, automatic timer cleanup, or overlap overrides.
