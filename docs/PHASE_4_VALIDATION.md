# Phase 4 Closeout Review and Billing Handoff

Phase 4 adds office review decisions, immutable correction versions, effective reviewer adjustments, operational disposition, final Service Ticket completion, and an internal billing handoff queue. It does not deploy, modify the beta portal, create invoices, or connect to an accounting system.

## Schema additions

Migration `2026_07_31_100000_create_closeout_review_tables.php` is additive and reversible:

- `closeouts.parent_closeout_id` links immutable submitted versions to the next correction version.
- `visit_part_proposals.source_proposal_id` identifies proposals copied into a correction draft.
- `closeout_reviews` stores one immutable decision per submitted closeout version, including safe self-review and disposition flags plus an idempotency token.
- `closeout_review_adjustments` stores reasoned effective time or proposal values without changing technician source records.
- `billing_handoffs` stores exactly one handoff per completed Service Ticket and approved closeout, effective totals, ready/handed-off state, actor/timestamp, and an acknowledgment token.

Merged Phase 0–3 migrations were not changed.

## Authorization matrix

| Role | Inspect review packet | Approve / return | View billing queue | Acknowledge handoff |
| --- | --- | --- | --- | --- |
| Super Admin | Yes | Yes, including audited self-review | Yes | Yes |
| Dispatcher | Yes | No | No | No |
| Technician | Assigned field versions only | No | No | No |
| Reviewer | Yes | Yes, except own submissions | No | No |
| Billing | No private evidence | No | Yes | Yes |

`closeouts.inspect`, `closeouts.review`, `billing_handoffs.view`, and `billing_handoffs.manage` remain independent capabilities. Active membership, explicit capability overrides, active-organization scoping, current-version checks, and 404 responses for cross-organization identifiers are enforced server-side.

## Review and outcome matrix

| Decision / outcome | Visit result | Service Ticket result | Billing handoff |
| --- | --- | --- | --- |
| Return for correction | `returned_for_correction`; new draft version becomes current | Unchanged | None |
| Approve resolved, no active follow-up | `approved` | `completed` | One `ready` handoff |
| Approve resolved with active follow-up | `approved` | Remains open | None |
| Approve needs return trip | `approved`; linked planned return remains | Remains open | None |
| Approve on hold | `approved` | Remains on hold | None |
| Approve customer unavailable / follow-up | Current visit remains `customer_unavailable`; one planned follow-up | Remains open | None |
| Approve customer unavailable / hold | Current visit remains `customer_unavailable` | Becomes on hold | None |
| Approve customer unavailable / cancel | Current visit remains terminal; other nonterminal visits cancel | Becomes canceled | None |

Correction versions copy narrative, proposals, outcome, fallbacks, and acknowledgment. Prior time and photos remain linked to their submitted version and are read-only. Inherited active photos satisfy resolved evidence requirements; supplemental photos retain the existing per-version limits. Resubmission preserves the original acknowledgment timestamp and reuses an existing linked return visit.

Time adjustments approve minutes or exclude an entry. Proposal adjustments include/exclude and may change effective quantity, unit, or billing treatment. Adjustment reasons are stored on the authorized review record, while audit metadata contains only IDs, states, and changed field names.

## Billing behavior

Approval, ticket completion, and handoff creation share one transaction. A unique Service Ticket key and decision tokens make retries idempotent. `BillingHandoffCreated` implements after-commit dispatch. Every completed ticket enters the queue, including warranty and no-charge work. Billing users see ticket/customer identifiers and effective time/proposal values but not closeout narratives, private media, acknowledgment details, internal notes, or unrestricted audit data.

## Local data preservation

The active local environment uses SQLite. Before migration, `php artisan db:show --counts` recorded one organization, user, membership, customer, contact, Service Ticket, visit, and visit assignment; two service locations; ten audit events; and no closeouts, time entries, media, or proposals at that snapshot.

A verified backup was created at:

`storage/app/backups/phase3-before-phase4-20260731-121800.sqlite`

- Size: 397,312 bytes
- SHA-256: `46528D548297FCD3843194FCE8DE469A13AAA583D233E2021F7B87A1C81D89ED`

The migration was first applied to a disposable copy and verified to retain all Phase 3 columns and rows. The real additive migration and idempotent seed then completed without replacing `.env`. Existing business rows remained present. One closeout and one time entry created in the active environment after the initial snapshot were also retained. Migration count increased from 11 to 12, capabilities from 13 to 14, and role-capability pivots from 33 to 35.

`composer phase:update` was attempted but could not complete on this workstation: Composer stalled before reaching the Docker step, and Docker is not installed. Its non-destructive operations were completed individually with the existing lockfiles: migration, idempotent seed, `npm ci`, and production build.

## Validation results

Completed locally on July 31, 2026:

- Phase 4 lifecycle suite: **10 passed, 74 assertions**.
- Full PHPUnit suite after the local-time UI correction and review-detail regression test: **57 passed, 362 assertions**.
- `composer validate --strict`: passed.
- `vendor/bin/pint --test`: passed.
- `php artisan view:cache`: passed.
- `npm ci`: passed with zero reported vulnerabilities.
- `npm run build`: passed with Vite 7.3.6 and 56 transformed modules.
- `git diff --check`: passed.
- Disposable SQLite migration and preservation check: passed.
- Responsive browser smoke at 390 x 844: passed with no horizontal overflow, 44px email and submit controls, and a visible keyboard-focus ring. The unauthenticated desktop login shell was also verified at 1440 x 900. Authenticated review and billing flows are covered by the feature suite because local credentials were not changed for manual testing.
- Operational timestamps retain UTC storage and machine-readable values but render in the applicable organization or visit timezone with an explicit timezone abbreviation.

Docker is not installed on this workstation, so the MySQL 8.4 validation is delegated to GitHub Actions. CI repeats fresh migrations, idempotent seeding, tests, formatting, frontend build, and diff checks against MySQL 8.4.

## Rollback and recovery

`php artisan migrate:rollback --step=1` removes Phase 4 review, adjustment, and handoff data plus the two lineage columns. Use it only intentionally. The timestamped SQLite backup above is the recovery point for the pre-Phase 4 local state. Restoring it would also discard legitimate records created after the snapshot.

## Exclusions

Phase 4 does not add invoice creation, payments, accounting export/synchronization, notifications, inventory mutation, production object storage, offline synchronization, deployment, beta changes, or production cutover. Completed Service Tickets cannot be reopened in this phase.
