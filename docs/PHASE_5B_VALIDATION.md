# Phase 5B Validation

## Delivery

- Office Service Ticket visits expose capability-gated execution through a responsive native dialog.
- Field Today includes authorized visits from the previous seven organization-local calendar days.
- Direct visit and whole-ticket cancellation require confirmation before active timers are stopped.
- Canceled visits preserve history and completed-time correction while blocking new execution and closeout writes.
- Super Admin can move eligible unused or canceled visits into a recoverable Admin Archive.
- Archived visits leave operational queues and completion checks; evidence-bearing visits cannot be permanently deleted.
- Super Admin can complete one eligible Visit from an office administrative-closeout dialog using the standard resolved evidence rules.

## Authorization

| Capability | Office behavior |
| --- | --- |
| `visits.execute_assigned` | Execute an assigned visit and manage personal time. |
| `visits.execute_any` | Execute any organization visit and manage completed time for active assigned crew. |
| `visits.archive.manage` | Archive, restore, and permanently delete eligible evidence-free visits. |
| `closeouts.manual_complete` | Create, submit, self-review, and complete an eligible Visit and its Service Ticket from Office. |
| Neither | No execution dialog or write access. |

Super Admin receives all listed capabilities through the existing all-capabilities seed. Existing Dispatcher, Reviewer, Billing, and Technician defaults are unchanged. Explicit capability grants, denials, and inactive memberships remain authoritative.

## Administrative closeout

- The selected Visit may be planned, scheduled, assigned, En Route, or On Site, but the ticket must have no active timers and every other nonarchived Visit must be approved, canceled, or customer-unavailable.
- The full-viewport phone / 96vw × 92vh desktop dialog reuses an existing draft and preserves completed time, private media, proposals, and optimistic content versioning.
- Manual completion is locked to Resolved and requires diagnosis, work performed, acknowledgment or fallback, photo or no-photo fallback, an administrative reason, and explicit confirmation.
- One row-locked transaction submits the closeout, creates an immutable administrative Super Admin self-review, approves the Visit, completes the ticket, and creates exactly one ready billing handoff. Submission-token retries are idempotent.
- Administrative reason contents are retained only in the authorized review record. Audit and incident metadata contain IDs, state names, and field names only.

## Admin Visit Archive

- Ticket completion is re-evaluated whenever a visit is approved and whenever an archived visit is removed from operational blockers. Multi-visit tickets therefore complete correctly when approvals occur out of chronological order or an accidental return visit is archived after the final resolved closeout was approved.

- Archiving is limited to planned, scheduled, assigned, or canceled visits without active timers, submitted closeouts, or nonarchived return-trip children.
- Archive actions require a reason and explicit confirmation. Rejected attempts and accepted archive, restore, and purge actions record safe audit metadata without reason content.
- Pre-execution visits restore only to open or on-hold Service Tickets with valid active field assignments. Canceled visits remain canceled when restored.
- Permanent deletion requires the exact visit ID and is blocked by execution timestamps, time entries, closeouts, billing handoffs, return-trip children, or closeout return references.
- Archived visits are excluded from Service Ticket history, Dispatch, field queues/history, workload counts, searches, health scans, and final-closeout blockers.

## Data preservation and rollback

Phase 5B adds one reversible migration containing Visit soft-delete and archive attribution fields. It does not replace `.env` or rewrite existing operational rows.

Before migration, a verified backup was created at `storage/app/backups/phase5b-before-visit-archive-20260808.sqlite` with SHA-256 `3EEFF46BAA752F15B8E04D5AA98B4FA951A1922CA979928FCD819DDE8B882A46`. Isolated restore verification passed with SQLite integrity `ok`, 35 tables, and matching migration/table counts, relationships, and representative workflows.

Pre- and post-migration operational counts matched exactly: 1 organization, 1 user, 2 customers, 2 Service Tickets, 5 visits, 4 closeouts, and 1 billing handoff. The idempotent seed added only `visits.archive.manage` and synchronized Super Admin; `.env` was unchanged.

Before the administrative-closeout migration, a second verified backup was created at `storage/app/backups/phase5b-before-admin-manual-closeout-20260808.sqlite` with SHA-256 `2D50584DD6A748A11BEED67915C275CBD7EBF267B5D16FA0D189A86EEF913CCD`. Isolated restore verification passed with SQLite integrity `ok`, 35 tables, and matching migrations, counts, relationships, and representative workflows. The operational counts remained 1 organization, 1 user, 2 customers, 2 Service Tickets, 5 visits, 4 closeouts, 3 closeout reviews, and 2 billing handoffs. Only migrations increased from 14 to 15 and capabilities from 17 to 18; `.env` was unchanged.

Rollback removes only the Visit archive columns and index. Because rollback would make archived visits visible again and discard archive attribution, verify recovery on an isolated target before any persistent rollback.

## Validation results

- Focused Admin Visit Archive PHPUnit: 7 tests, 82 assertions passed.
- Focused Admin Manual Closeout PHPUnit: 6 tests, 56 assertions passed.
- Complete PHPUnit: 89 tests, 651 assertions passed.
- Composer strict validation and Pint check passed.
- Compiled Blade syntax: 106 files passed.
- Vite production build passed with 56 transformed modules.
- Authenticated Playwright Chromium: 2 passed and 2 project-inapplicable variants skipped across desktop and 390×844 mobile projects.
- Axe reported no serious or critical findings on the office execution dialog, administrative-closeout dialog, or field workflow.
- Axe reported no serious or critical findings on the Admin Archive page.
- Modal viewport, Escape close, focus return, mobile overflow, 44px controls, offline write prevention, and retry messaging passed.
- Post-optimization beta benchmark: Today 18 queries, Dispatch 16, Service Ticket detail 17, Review detail 22; all response and query budgets passed.
- `git diff --check` passed.

The isolated beta SQLite database was deterministically reset for browser validation. The active development database received only the additive archive migration and idempotent capability seed; it was not reset or replaced.

## Exclusions

The ordinary office execution dialog still excludes closeout narrative and evidence; administrative completion uses its separate Super Admin-only dialog. Approved/reviewed visits and their evidence cannot be archived or permanently deleted. Archive retention is manual; no automatic purge, deployment, or external integration is included.
