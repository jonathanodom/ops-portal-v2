# Submitted Visit Time Corrections

## Baseline and scope

PR A branches from `main` at `b446cda01676dba911553ea5524c5514d2a17cee`. It corrects factual clock mistakes on ended Visit time entries after Closeout submission. It does not add Work Items, time allocation, per-task billing, staff timesheets, or post-approval reconciliation.

## Raw and effective time

`visit_time_entries.started_at` and `ended_at` remain the original captured evidence. A submitted correction writes only nullable `corrected_started_at` and `corrected_ended_at` overrides.

The `VisitTimeEntry` model is the canonical effective-time API:

- `effective_started_at` resolves corrected start, then raw start.
- `effective_ended_at` resolves corrected end, then raw end.
- `effectiveDurationSeconds()` calculates operational duration from that interval.

Operational displays, review defaults, completion/billing minutes, trip-charge calculation, printable Work Orders, and overlap diagnostics use the effective interval. Corrected Office views retain and label the original interval.

## Immutable correction history

Every correction appends a `visit_time_entry_corrections` row containing its organization, entry, deterministic sequence, previous effective interval, new interval, required reason, actor, and timestamps. The current override is updated in the same row-locked transaction. Application-level updates and deletes of history rows are rejected.

The second and later history rows use the preceding effective interval as their `previous_*` values. Raw evidence is never overwritten.

## Authorization

Submitted correction requires all of the following:

1. An active membership in the entry's organization.
2. The exact `super_admin` role key.
3. The effective `visit_time.correct_submitted` capability.
4. Matching active Organization scope for entry, Visit, and Service Ticket.

The Super Admin role receives the capability through idempotent access-control seeding. No other seeded role receives it. A grant to a non-Super-Admin is insufficient, `visits.execute_any` is insufficient, and an explicit Super Admin denial wins.

Normal draft correction remains unchanged and continues to update draft raw timestamps through the existing execution workflow.

## Lifecycle boundary

An entry attached to submitted Closeout v1 remains correctable while the Visit's current Closeout is returned v2 draft or resubmitted v2. Eligibility follows the submitted entry and Visit lifecycle rather than only `visits.current_closeout_id`.

Correction stops permanently for this workflow when any Closeout on the Visit has an approved Review, the Service Ticket is completed, or a Billing Handoff exists. It also rejects active timers and canceled or archived operational records. Existing Billing Handoffs are never recalculated.

Post-approval factual reconciliation requires a separate future workflow.

## Overlap semantics

All creation and correction overlap queries use bounded SQL `COALESCE(corrected_*, raw_*)` expressions with the existing half-open rule:

```text
existing.start < proposed.end
AND (existing.end IS NULL OR existing.end > proposed.start)
```

Adjacent entries remain valid. Queries retain row locks, self-exclusion, deterministic effective-start/ID ordering, and privacy-safe cross-organization diagnostics.

## Review adjustments

A submitted correction says the factual clock interval was wrong. A `CloseoutReviewAdjustment` says the factual interval may be correct, but only a different duration is approved or billable. Review adjustments remain unchanged, and their approved minutes or exclusion still override the effective duration during completion.

## Audit and privacy

Accepted corrections emit `visit_time.submitted_corrected` with only Visit, entry, owner, sequence, and changed-field identifiers. The free-text reason is retained only in immutable correction history and is excluded from Audit Event metadata.

Cross-organization entry URLs return 404 and use the existing safe security audit convention.

## Validation

- New submitted-correction feature coverage: 7 tests, 70 assertions.
- Existing focused execution, time-conflict, review, billing-handoff, trip-charge, and Work Order coverage: 58 tests, 520 assertions.
- Full PHPUnit: 392 tests, 3,245 assertions.
- Isolated migration rehearsal: fresh migrate, two-step rollback, and reapply passed.
- Beta fixture validation and query benchmarks passed; responsive Playwright/axe results are recorded in the draft PR.

## Future boundary

Service Ticket Work Items, Visit task lists, time allocation, per-Work-Item commercial treatment, and post-approval reconciliation remain separate follow-up PRs described by the FSM Work Items and Time Attribution roadmap.
