# Service Ticket Files

## Scope and baseline

- Branch: `feat/service-ticket-files`
- Originating `main`: `ba7f3c6fd29c06defc1a46d8b2be16bee07c949d`
- Delivery package: RC-FEAT-011, independent from Package A
- Ownership: files belong directly to a Service Ticket and never to a Visit or Closeout

Ticket files are private operational references. They do not participate in Visit or Ticket completion, Closeout evidence/readiness, Billing Handoffs, invoices, payments, or Projects.

## Schema and storage

The additive `service_ticket_files` table records organization and Ticket ownership, uploader, private disk/key, safe original filename metadata, MIME type, byte size, optional caption, and retained removal metadata.

- Accepted: PDF, JPEG, PNG, WebP, HEIC, and HEIF
- Maximum: 20 MB
- Disk: `SERVICE_TICKET_FILE_DISK`, default `local`
- Keys: server-generated UUID paths; original filenames never control storage paths
- Retrieval: authenticated organization-scoped controller with `private, no-store` and `nosniff` headers
- Removal: metadata changes from `stored` to `removed`; private-object cleanup is queued after commit
- Removed records cannot be downloaded and remain available for durable audit history

No folders, versions, approval workflow, tags, public links, customer access, ZIP archives, or generalized document subsystem were added.

## Authorization

| Action | Required authority |
| --- | --- |
| List and download | `service_tickets.view` plus normal organization-scoped Ticket policy |
| Upload and remove | `dispatch.manage` plus normal organization-scoped Ticket policy |

Explicit membership grants/denials and inactive membership restrictions remain authoritative. Field users receive no Ticket-file access by default.

## Audit events

- `service_ticket_file.uploaded`
- `service_ticket_file.removed`

Metadata contains only Ticket/file IDs, MIME type, and byte size. Captions, original filenames, storage keys, and file contents are excluded.

## Validation

- Focused PHPUnit: 7 tests, 50 assertions — passed
- Full PHPUnit (SQLite local parity run): 315 tests, 2,569 assertions — passed in 153.85 seconds
- Composer validation: passed
- Composer security audit: no advisories
- Pint: passed
- Compiled Blade lint: 170 files passed
- Vite production build: passed
- Migration rollback/reapply on isolated beta: passed
- Beta fixture validation and SQLite integrity: passed with exact fixture counts
- Beta benchmark (10 runs): Dashboard 11.1 ms/14 queries; Today 8.6 ms/9; Dispatch 11.0 ms/10; Projects 21.5 ms/16; Project detail 17.8 ms/24; Ticket detail 16.9 ms/23; Review detail 15.5 ms/28; media first byte 0.2 ms
- Playwright/axe: 16 passed, 12 intentionally project-skipped; no serious or critical axe violations
- `git diff --check`: passed

Ticket detail adds one bounded eager-load query for files and their uploader (22 to 23 queries locally; 32 to 33 on MySQL). The focused performance change raises only the Ticket-detail ceiling from 32 to 33 while preserving the 750 ms response budget and every other query ceiling. MySQL 8.4 migration, complete regression, backup/restore, beta, and browser parity remain enforced by the draft PR workflow.

## Review screenshots

- [Phone Ticket-files workflow](ui-review/field-test-2026-08-17/package-b/ticket-files-390x844.png)
- [Desktop Ticket-files workflow](ui-review/field-test-2026-08-17/package-b/ticket-files-1440x900.png)

## Rollback

The migration `down()` removes only `service_ticket_files`. Before rollback, ensure private objects referenced by those records have been retained or deliberately removed. Rolling back this feature does not alter Service Tickets, Visits, Closeouts, evidence, Billing Handoffs, invoices, payments, Catalog data, Projects, or customer records.
