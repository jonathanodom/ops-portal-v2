# Commercial Operations V1 — Phase 4 Approval and Publication

## Scope and gate

Phase 4 extends accepted Phase 3 commit `3b4ed20784fc1f40b71d09b8244629672fccf8a3` on `feat/commercial-operations-v1-checkpoints-0-3`. The entry gate confirmed a clean branch, green PR #52 CI, applied Phase 0–3 migrations, and the retained local SQLite environment before any Phase 4 schema change.

This checkpoint provides internal approval, reusable Proposal content, immutable publication, private PDF/media, and safe delivery infrastructure. It intentionally does not expose a usable customer token route, comments, customer choices, change requests, signatures, acceptance, Opportunity automation, Invoices, Project conversion, Change Orders, or any Phase 5 behavior.

## Additive schema

Migration `2026_08_28_010000_create_commercial_approval_publication_foundation` adds:

- Proposal visibility defaults on `organization_commercial_settings`.
- Organization-scoped reusable `commercial_content_blocks` and immutable versioned `commercial_terms_sets`.
- Four editable `proposal_templates` with ordered `proposal_template_sections`.
- Terms provenance and immutable terms snapshots on `commercial_revisions`.
- Optional reusable-content provenance on `commercial_revision_sections`.
- Private, opaque, hash-recorded `commercial_revision_media`.
- Content-hash-bound `commercial_revision_approvals`.
- Immutable `proposal_publications` with customer-safe snapshots, snapshot hashes, brand references, visibility, expiration, totals, and private PDF state.
- Hash-only `proposal_recipients` and `proposal_share_links`.
- Idempotent `proposal_delivery_attempts` for email and reminder infrastructure.

Migration `2026_08_28_020000_seed_commercial_publication_capabilities` idempotently applies the access matrix. Both migrations are additive and reversible; rollback removes only Phase 4-owned tables/columns. Once immutable publications exist, rollback is a recovery operation and the retained backup must be preserved.

## Authorization

Phase 4 adds:

- `quotes.publish`
- `quotes.approve`
- `proposal.engagement.view`
- `proposal.templates.manage`

Super Admin receives every capability through the existing all-capabilities seeding convention. Dispatcher retains the accepted Phase 1–3 defaults and receives none of these four automatically. Explicit membership grants and denials remain authoritative, inactive memberships remain blocked, and every route/query is scoped through the active organization.

Approval authority remains separate from `quotes.manage` and `quotes.cost_margin.view`. Proposal publication, recipients, delivery state, media streaming, PDF access, and library management are policy/capability protected independently of hidden controls.

## Approval and publication behavior

Approval evaluation records only safe numeric and identifier snapshots for:

- overall gross margin below 20%;
- effective discount above 15%;
- resolved lines sold below cost;
- Catalog sell-price overrides; and
- terms outside an approved organization terms version.

Exact 20% margin and 15% discount boundaries pass. A no-exception revision records `policy_pass`; exception revisions enter `pending_approval`. Decisions bind to the revision content hash. Financial, quantity, option, scope, terms, payment-schedule, or media changes produce a new hash and make earlier decisions unusable. Rejection returns the revision to Draft; approval locks that exact revision.

Publication requires an approved/policy-passed current hash and an active template. Acceptance-enabled publications with a payment schedule require an active Service Location; a non-acceptance Budgetary Estimate may remain customer-wide. The publication freezes:

- customer, site, seller, template, ordered sections, terms, scope, line, option, milestone, media, total, visibility, expiration, and branding references;
- customer-safe line values without internal cost, margin, Package demand, approval history, labor cost, storage keys, or internal notes; and
- a SHA-256 publication hash over the complete frozen snapshot, including visibility, expiration, and brand-asset identity.

Publication is idempotent per revision. Later Catalog, Quote-template, organization, or branding changes do not rewrite the publication. The queued PDF renderer reads the frozen snapshot, writes an opaque private object, records its SHA-256 hash, and is safe to retry. Editable template headings/order drive both Office preview and PDF composition.

## Content, media, and delivery infrastructure

- The Proposal library provides reusable editable scope blocks, append-only approved terms versions, and Budgetary Estimate, Quick Quote, Full Project Proposal, and Change Order templates.
- Draft Quotes can copy then edit reusable scope content, select or override terms, upload private images/documents, and add validated HTTPS video references.
- Media objects use opaque keys on the configured private disk. Authorized streaming is `private, no-store`; removal immediately leaves the Draft and queues object cleanup after commit.
- Recipient and generic-link secrets are high-entropy and stored only as SHA-256 hashes. Raw tokens are shown once to authorized Office staff for local infrastructure testing.
- There is deliberately no public `/proposals/*` route in Phase 4.
- Delivery jobs send only in `local` or `testing`; other environments record `phase4_customer_delivery_disabled` and send nothing.
- Organization-local 7-day and 2-day reminder jobs create idempotent delivery attempts. Phase 5 will make customer routing and actual customer engagement usable.

## Preserved local database

Before migration, a consistent backup was created and verified at:

`storage/app/backups/ops-commercial-phase4-pre.sqlite`

SHA-256:

`D14C725D87BF5B6D9FDF7338778D1089265E6034339B61D2D356E3B02A83BD14`

Restore verification passed for 95 tables, migrations, counts, key relationships, and representative workflows. The active `.env` was not changed or replaced. Phase 4 migrations are batch 12.

Core retained counts before/after migration were unchanged: 1 Organization, 1 User, 8 Customers, 12 Service Tickets, 13 Visits, and 13 Invoices. The backup contained 1 Opportunity, 1 Commercial Document, and 1 Revision. Two additional Commercial Revisions were created in the retained environment while implementation was in progress and remain preserved; Phase 4 did not reset or delete them. Phase 4 currently has 4 default templates and 0 publications in the retained database.

The isolated beta reset used only the beta database. It did not change the active development database.

## Validation results

- Focused Phase 4: **9 tests, 46 assertions — passed**.
- Complete PHPUnit suite: **461 tests, 4,013 assertions — passed**.
- Composer validation: passed.
- Composer security audit: no advisories.
- Pint: passed.
- Compiled Blade PHP lint: **210 files — passed**.
- Vite production build: passed.
- `git diff --check`: passed.
- Disposable SQLite fresh migration, two-migration rollback, and re-application: passed; the scratch database was removed after verification.
- Isolated beta fixture validation: passed at 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, 500 media records, and existing Project scenarios.
- Beta p95 benchmarks passed: Dashboard 23.1 ms, Today 13.0 ms, Dispatch 25.5 ms, Projects 18.9 ms, Customer detail 16.7 ms, Project detail 28.1 ms, Ticket detail 20.7 ms, Review detail 15.3 ms, and media first byte 0.1 ms.
- Existing Playwright/axe responsive suite: 29 passed and 25 intentionally project-specific skips.
- Focused Phase 4 Playwright/axe coverage: passed at 390, 768, 1280, 1440, and 1920 pixels; the paired mobile-project duplicate was intentionally skipped because the test itself loops through all required widths.
- MySQL 8.4, isolated restore rehearsal, and aggregate GitHub validation are recorded on PR #52 after the Phase 4 commit is pushed.

## Owner UI checklist

1. In Commercial Settings, verify the 20% margin floor, 15% discount ceiling, 30-day expiration, 7/2-day reminders, visibility defaults, and Proposal-library link.
2. In Proposal Library, edit each template and section heading/order; add a reusable Scope block and a new approved terms version.
3. Create a Draft Quote, copy/edit a Scope block, select approved terms, add private media and an HTTPS video, and confirm the selected files never expose a storage path.
4. Submit an exact-boundary Quote and confirm it policy-passes. Submit margin, discount, below-cost, manual-price, and custom-terms exceptions and confirm they enter the approval queue.
5. Reject one request, edit the Draft, resubmit, then approve the current hash. Confirm a stale decision cannot be applied.
6. Verify approval authority, Quote editing, and cost/margin visibility are independently gated.
7. Publish with each template and visibility choice. Confirm the Office preview and PDF use the immutable template snapshot and contain no cost, margin, Package demand, internal note, approval, or storage-key data.
8. Change Catalog/template content after publication and confirm the existing preview/PDF remains unchanged.
9. Create recipient and generic-link records; confirm tokens are shown once, can be revoked, and are clearly labeled local Phase 4 infrastructure with no usable customer route.
10. Queue a local email, inspect delivery state, retry PDF failure if simulated, and withdraw a publication while retaining internal history.
11. Review the Proposal Library and approval queue at 390, 768, 1280, 1440, and 1920 pixels for focus visibility, no horizontal overflow, and 44px controls.
12. Recheck retained Customers, Tickets, Visits, Closeouts, Projects, Catalog, Billing, Invoices, Payments, and Field workflows.

## Deferred boundary

Phase 5 remains blocked. This checkpoint does not add a usable customer token surface, view tracking, customer comments, option selection, Request Changes, signature, acceptance, Opportunity stage automation, deposit billing, Project conversion, Change Orders, or production delivery.

`WAITING FOR OWNER UI ACCEPTANCE — PHASE 4`
