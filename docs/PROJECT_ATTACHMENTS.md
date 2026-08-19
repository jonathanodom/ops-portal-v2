# Project Files and Photos

## Baseline and scope

- Branch: `feat/project-attachments`
- Originating `main`: `2b43e116f2ee95116e797fef1d1f70213cad6112`
- Starting GitHub Actions run: `32188871532` (green)
- Ownership: each attachment belongs directly to one Organization and one Project. It is independent of Service Tickets, Visits, Closeouts, Tasks, Workstreams, and Milestones.

This delivery adds private Project context only. Uploading or removing an attachment does not alter Project or Portal operational, billing, invoice, payment, Catalog, or inventory state.

## Schema and storage

The additive `project_attachments` table stores Organization, Project, uploader, bounded category, retained state/removal metadata, private disk and opaque key, sanitized original-name metadata, authoritative MIME type, byte size, and optional caption.

- Disk: `PROJECT_ATTACHMENT_DISK`, default `local`
- Key: `project-attachments/YYYY/MM/{uuid}.{server-selected-extension}`
- Maximum: 20 MB per file at Laravel validation
- Images: JPEG, PNG, WebP, HEIC, HEIF
- Documents: PDF, DOCX, XLSX, CSV, TXT
- Rejected: HTML, SVG, scripts/executables, archives, macro-enabled Office documents, and arbitrary binary content

DOCX and XLSX uploads are inspected as Open XML packages, including required internal entries and rejection of VBA payloads. Client extensions never authorize arbitrary binary content. Original names are stored only as sanitized metadata and never control the object path.

Storage is private: the application creates no public URL. Authenticated controllers stream files with the stored MIME type, `Cache-Control: private, no-store`, and `X-Content-Type-Options: nosniff`. Browser-renderable images may be shown through that same authorized route; no public thumbnails or conversion system exists.

If object storage succeeds but database persistence fails, the new object is deleted. Removal changes the record to `removed`, records actor/time, blocks further retrieval, and queues object cleanup only after database commit.

## Categories

Stable metadata categories are Site Photo, Design Document, As-Built, Vendor Document, Equipment List, Customer-Supplied, Reference, and Other. Categories do not trigger workflow behavior.

## Authorization and tenant boundary

| Action | Authority |
| --- | --- |
| List, preview, download | Existing `projects.view` / `ProjectPolicy::view` |
| Upload, remove | Existing `projects.manage` / `ProjectPolicy::update` |

The active Organization, route Project, and attachment ownership must all match. Cross-Organization and cross-Project identifiers return not found. `projects.tasks.manage` alone does not grant attachment mutation, and explicit membership overrides remain authoritative.

Completed and canceled Projects keep stored attachments readable but reject uploads and removals, matching the existing Project operational-mutation rule.

## Audit and privacy

`project_attachment.uploaded` and `project_attachment.removed` are recorded against the Project so they appear in bounded Project activity. Safe metadata is limited to attachment ID, category, MIME type, and byte size. Captions, filenames, storage keys, and file contents are excluded.

## Query and UI behavior

Project detail loads only stored attachment metadata and uploader identity, newest first. It never reads object contents into the page query. This adds one bounded eager-load query, so the isolated Project-detail benchmark ceiling changes from 30 to 31 queries while retaining the 750 ms response budget.

The Files & Photos section supports normal phone gallery/Files selection without forcing camera capture. Managers receive the upload/remove controls; viewers receive private view/download controls. HEIC/HEIF remain downloadable when a browser cannot render them.

## Deployment and rollback

Production PHP, web-server, and proxy request limits must exceed 20 MB plus multipart overhead. The configured private disk must be writable by the web process and readable by the queue worker. Storage paths must not be web-addressable.

Rollback removes only `project_attachments`. It does not alter Projects, linked Tickets, Visits, Closeouts, Service Ticket files, Visit media, Billing Handoffs, invoices, payments, Catalog, or inventory. Before a production rollback, retain or intentionally remove private objects referenced by the table to avoid orphaning them.

## Validation record

The focused suite covers accepted formats, spoofing and unsafe formats, size/category validation, opaque keys, filename sanitization, ownership, read/write capabilities, explicit overrides, tenant and Project isolation, lifecycle restrictions, retained removal, after-commit cleanup, private headers, and storage/database failure compensation.

- Focused Projects, Project-to-Ticket, Service Ticket file, and Visit media regression: 52 tests, 442 assertions, passed.
- Full PHPUnit: 349 tests, 2,849 assertions, passed.
- Composer validation and security audit: passed; no advisories.
- Pint, compiled-Blade lint (197 files), Vite production build, and `git diff --check`: passed.
- Isolated migration fresh apply, rollback, and reapply: passed.
- Beta exact-count and SQLite integrity validation: passed.
- Beta Project detail: p95 92.8 ms, 25 queries (750 ms / 31-query ceiling).
- Project responsive/axe coverage: desktop and mobile passed across 390, 768, 1280, 1440, and 1920 px; the normal file input has no forced capture directive.
- Full Playwright/axe scenarios passed locally after isolated reruns of two unrelated axe timeout cases; MySQL browser parity remains enforced by the draft PR workflow.

## Non-goals

No folders, arbitrary tags, versions, approvals, signatures, OCR, image/document conversion, public links, customer/Field access, cloud-drive integration, Task/Workstream/Milestone attachments, Ticket file copying, Catalog/inventory integration, or Project billing is included.
