# Phase 3 Mobile Field Execution

Phase 3 adds the mobile execution and closeout capture layer without importing beta data, resetting the local database, deploying, or adding office review decisions.

## Schema additions

Migration `2026_07_31_080000_create_field_execution_tables.php` is additive and reversible. It creates:

- `closeouts`: versioned shared narrative, optimistic `content_version`, outcome, acknowledgment/fallback, evidence fallback, idempotent submission token, and linked return visit.
- `visit_time_entries`: per-user travel, on-site, and other time with a nullable unique `active_user_id` enforcing one active timer per user.
- `visit_media`: private opaque object references, category, MIME/size metadata, and soft-removal state.
- `visit_part_proposals`: descriptive parts/equipment proposals and billing treatment without inventory or invoice mutation.
- `visits.current_closeout_id`: the active closeout version for a visit.

Merged Phase 0–2 migrations were not changed.

## Authorization matrix

| Role | Field workspace | Edit assigned shared draft | Submit | Inspect submitted evidence in office |
| --- | --- | --- | --- | --- |
| Super Admin | Yes | All visits | Any visit through audited `visits.execute_any` | Yes |
| Dispatcher | Yes | No by default | Explicit `visits.execute_any` only | Yes |
| Technician | Yes | Assigned visits | Lead assignment only | No office access |
| Reviewer | No | No | No | Yes |
| Billing | No | No | No | No |

Super Admin is synchronized to every seeded system capability, including `closeouts.inspect`, `visits.execute_assigned`, and `visits.execute_any`, so the sole administrator can use every current feature. Other roles remain narrowly scoped. Every `visits.execute_any` use during submission is recorded in safe audit metadata. Inactive memberships, explicit deny overrides, and cross-organization URLs remain enforced server-side.

## Outcome and evidence matrix

| Outcome | Required closeout data | Atomic effect |
| --- | --- | --- |
| Resolved | Diagnosis, work performed, acknowledgment or fallback, and an active photo or categorized no-photo reason/detail | Visit becomes `pending_closeout` |
| Needs return trip | Diagnosis, work performed, return reason, unfinished work, needed equipment, recommendations, and acknowledgment or fallback | Creates one linked planned return visit and moves the source visit to `pending_closeout` |
| On hold | Hold reason, recommendations, and acknowledgment or fallback | Places the Service Ticket on hold and moves the visit to `pending_closeout` |
| Customer unavailable | Categorized reason and detail | Visit becomes `customer_unavailable`; Service Ticket stays open |

Submission is lead-only except for an audited `visits.execute_any` grant, which Super Admin now receives by role. The submission token and a row lock make retries idempotent. Submission stops all active closeout timers with source `system_auto`; narrative, time, media, and proposals are immutable afterward.

## Mobile and privacy behavior

- The phone-first visit workspace includes persistent status/submission actions, work context, crew, individual time, shared closeout, private photos, proposals, and ticket history.
- Draft saves use optimistic locking. Stale saves return HTTP 409 and do not overwrite current values.
- Dirty-page warnings retain unsaved values only in the current page; no closeout content is written to local storage.
- Offline state disables form writes. Uploads report progress and require an explicit retry after failure or reconnection.
- Photos accept JPEG, PNG, WebP, HEIC, and HEIF, up to 20 active objects and 20 MB each.
- Objects use opaque generated keys on `FIELD_MEDIA_DISK` (default `local`, rooted at `storage/app/private`). There are no public URLs or raw paths in responses.
- Authorized controllers stream stored evidence. Draft evidence is field-executor-only; office inspection is available only after submission and only with `closeouts.inspect`.
- Draft photo removal immediately excludes the record and queues private-object deletion after commit.
- Audits contain IDs, categories, state names, and changed field names. Narrative, addresses, contacts, access instructions, correction explanations, and sensitive reasons are excluded.

Production PHP configuration must permit at least 20 MB per uploaded file plus multipart overhead. Recommended minimums are `upload_max_filesize=21M` and `post_max_size=24M`; infrastructure values remain outside source control.

## Local data preservation

The active local environment uses SQLite even though CI and the intended development standard use MySQL 8.4. Before migration, the database contained one user, organization, membership, customer, contact, Service Ticket, visit, and visit assignment; two service locations; and nine audit events.

A verified backup was created at:

`storage/backups/database-phase3-before-20260731-094717.sqlite`

- Size: 344,064 bytes
- SHA-256: `149D10ED4BB3713EDAC8663469831EE749F0A5BC776D3DD9D419A9ED826C1087`

After `php artisan migrate --force` and `php artisan db:seed --force`, every pre-existing business row count was unchanged. The four new Phase 3 tables contained zero rows, migration count increased from 10 to 11, capabilities increased from 12 to 13, and role-capability pivots are now 33 after granting Super Admin every system capability. `.env` was not replaced or edited.

## Validation results

Completed locally on July 31, 2026:

- `php artisan test`: **46 passed, 281 assertions**.
- Phase 3 feature matrix: **10 passed, 86 assertions**.
- `php artisan view:cache`: passed.
- `vendor/bin/pint --test`: passed.
- `composer validate --strict`: passed.
- `npm run build`: passed (Vite 7.3.6, 56 modules transformed).
- `git diff --check`: passed.
- Disposable SQLite fresh migration, Phase 3 rollback, and re-application: passed.
- Small-phone browser smoke check at 390×844: login shell, labels, focusable authentication controls, branding, and responsive containment verified.

Docker is not installed on this workstation, so local MySQL 8.4 execution could not be repeated. GitHub Actions runs migrations, seeders, formatting, the full test suite, frontend build, and diff checks against MySQL 8.4. Authenticated multi-user visual walkthroughs remain a human QA item because Codex did not alter or request the preserved local account credentials; the equivalent field/office projections and write paths are covered by feature tests.

## Rollback and recovery

`php artisan migrate:rollback --step=1` removes the four Phase 3 tables and `visits.current_closeout_id`; it also deletes Phase 3 execution data and should only be used intentionally. The pre-migration SQLite backup above is the recoverable local snapshot. Capability seeding is idempotent and never replaces users or operational records.

## Exclusions

Phase 3 does not add offline synchronization, background uploads, image editing/compression, malware scanning, video, notifications, native applications, closeout review decisions, corrections after submission, version 2 closeouts, billing handoffs, inventory mutation, invoicing, deployment, beta changes, or production cutover.
