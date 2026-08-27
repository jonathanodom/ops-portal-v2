# Service Ticket Invoice Context V1

## Baseline and scope

Implementation branched from `main` at `828874e0d424674a7107af8de29f7cee2b7d9f14` after GitHub Actions run `32887635558` passed. This change adds customer-safe service context to Service Ticket-based invoices. It does not change invoice calculations, Billing Handoffs, payments, Service Ticket workflows, or direct invoices.

## Document behavior

| Invoice | Office draft/ready view | Issued presentation and PDF |
| --- | --- | --- |
| Service Ticket-based | Live customer-safe projection with an issue-time capture notice | Immutable `invoice_service_snapshots` payload only |
| Direct | Existing invoice experience; no Service Details panel | Existing presentation/PDF; no service snapshot |
| Legacy issued Ticket invoice | Historical-snapshot warning in Office | Existing minimal Ticket number/title/location; no live-history fabrication |

Financial invoice lines remain authoritative for charges. Service Details explains the requested and completed service without affecting totals.

## Snapshot schema and integrity

The additive `invoice_service_snapshots` table stores one row per Invoice with Organization, Invoice, Service Ticket, schema version, JSON payload, SHA-256, capture timestamp, and capture actor. V1 uses schema version `1`.

`InvoiceServiceContextProjection` is a dedicated scalar allowlist. `InvoiceServiceSnapshotFactory` canonicalizes associative keys while retaining list order, encodes the payload without escaped slashes or Unicode, and hashes that exact canonical representation. Model updates and deletes are rejected. There are no edit or refresh endpoints.

Tenant integrity is checked against the locked Invoice: Organization, Customer, Service Location, and Service Ticket must agree. The workflow never accepts a request-supplied Ticket identifier.

## Atomic issuance and retries

`InvoiceWorkflow::issue()` performs final calculation and validation, creates or verifies the snapshot, and only then changes the Invoice to `issued` inside the same database transaction. A projection or persistence failure rolls back both operations. PDF work remains queued after commit.

The Invoice row lock, unique Invoice constraint, and issue token preserve idempotency. A retry verifies an existing snapshot’s Organization/Invoice/Ticket identity and never refreshes it from live Service Ticket data.

Void retains the original snapshot. A reissue draft has no snapshot and previews current service context; issuing the replacement captures a new independently hashed snapshot. Existing issued and void Invoice generations are never changed.

## Customer-safe content

The allowlist contains:

- Ticket number, title, friendly state, requested scope, and customer-visible summary;
- Customer, Service Location/address/timezone, and customer-facing contact;
- Additional Work Item title, friendly status, discovered/handled Visit labels, and follow-up Ticket number;
- Visit date, friendly state, return lineage, technician names, and the earliest-to-latest completed effective on-site window;
- work performed, recommendations, outcome, customer-safe part description/quantity/unit;
- signed or fallback acknowledgment metadata without signature media.

It explicitly excludes travel and `other` time, summed crew hours, Work Item time allocations, reviewer adjustments/reasons, time-correction reasons, Billing disposition, office/internal notes, audit events, payment/provider data, Projects, private media, route URLs, storage paths, and signature images.

## Rendering and legacy policy

Draft and ready Office views build the live projection. Issued/void Office views, customer presentation, and PDFs read the stored JSON. The PDF job loads `serviceSnapshot`; it does not query detailed live Service Ticket history. Financial lines and totals remain before the page-break-aware Service Details continuation.

No migration backfill is performed. A pre-feature issued Invoice without a snapshot remains readable using its existing minimal Service Ticket identity. Retrying its PDF cannot create a snapshot. Reissuing it follows the normal new-generation capture behavior.

## Deletion, backup, and rollback

Unissued Invoice deletion now additionally refuses any Invoice carrying a service snapshot. Normal drafts never have one. Existing field-test purge behavior is not expanded; database cascade only follows an Invoice already selected by that guarded workflow. Local-example inventory/reset includes the new organization-scoped table. Backup and isolated-restore manifests validate the Invoice-to-snapshot relationship.

Rollback is `php artisan migrate:rollback --step=1` only when no retained issued invoices depend on the new table. Production rollback must retain issued commercial history; application rollback after issuance therefore requires a forward-compatible deployment rather than dropping snapshot data.

## Validation record

- Focused snapshot/projection/reissue/legacy/UI tests: 6 tests, 82 assertions passed.
- Billing, payment, Service Ticket document, effective-time, Work Item, allocation, and acknowledgment regression selection: 55 tests, 687 assertions passed. The Phase 6 Invoice subset contributed 25 tests and 307 assertions.
- Complete PHPUnit suite: 427 tests, 3,679 assertions passed in 94.1 seconds using a 512 MB CLI limit. The local destructive field-test flag was overridden to its CI default (`false`); `.env` was not changed.
- Migration fresh/rollback/reapply passed against an isolated SQLite database. The retained local operational database was not reset or replaced.
- Composer validation and audit, Pint check, compiled Blade cache/lint (194 files), Vite production build (57 modules), and `git diff --check` passed.
- Isolated beta setup and exact fixture validation passed: 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, 500 media records, and 2 Projects.
- Beta benchmark p95/query maxima passed: Office Dashboard 15.3 ms/31, Today 8.0 ms/9, Dispatch 19.3 ms/15, Projects 15.7 ms/16, Customer detail 12.6 ms/21, Project detail 20.6 ms/25, Ticket detail 15.3 ms/27, Review detail 14.5 ms/29, and media first byte 0.0 ms.
- Playwright/axe full matrix: 28 applicable tests passed and 24 intentionally skipped by project/fixture guards in 2.8 minutes. Focused Service Details coverage then passed at Office widths 390/768/1280/1440/1920 and customer-presentation widths 390/768/1280 (2 passed, 2 project-guard skips).
- Query observations: Ticket draft Office view 75 queries (ceiling 80); issued customer presentation 20 queries (ceiling 30). Issued detail reads the stored JSON snapshot rather than a detailed live Ticket graph.
- PDF validation passed through the issued-snapshot feature render assertions, existing Phase 6 Dompdf regression, compiled-Blade lint, and the page-break-aware Letter template. No signature-image or private-media dependency is introduced.

No deployment or merge is part of this PR.
