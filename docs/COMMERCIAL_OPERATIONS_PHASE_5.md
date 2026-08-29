# Commercial Operations V1 — Phase 5 Customer Response and Acceptance

## Scope

Phase 5 extends accepted Phase 4 commit `a5114bb93c720b33e04c6ee22fa3db88cb04c590` on `feat/commercial-operations-v1-checkpoints-0-3`. It activates the existing hash-only recipient and generic Proposal links, adds customer responses and immutable acceptance, and automates only the approved Opportunity stage changes.

This phase does **not** create Invoices, payments, deposits, Projects, Service Tickets, or Change Orders. An accepted Proposal remains visibly marked as billing pending until Phase 6.

## Additive schema

Migration `2026_08_28_030000_create_commercial_customer_response_acceptance` adds publication view/change/acceptance/extension timestamps; recipient/share-link view timestamps; engagement events; customer/staff comments; per-link option selections; email verification challenges; immutable acceptances and line selections; and immutable accepted payment milestones.

Tokens remain hash-only. Names, emails, IP addresses, and acceptance signer details use application encryption. Safe hashes support correlation without placing contact data, comment text, signature keys, or signature contents in audit metadata.

## Customer access and response rules

- Recipient and generic links resolve without an Office or Field shell and return `no-store`, `noindex`, and restrictive referrer headers.
- Revoked or unknown links return 404. Expired, withdrawn, superseded, change-requested, and accepted publications remain non-actionable reference views.
- Every view records an encrypted IP, one-way IP hash, bounded user agent, link identity, and timestamp, then queues an owner notification.
- Optional selections are scoped to the active secure link and totals are recalculated server-side with deterministic integer arithmetic.
- Comments may target the Proposal, a frozen section, or a frozen line. Customer text never enters audit metadata.
- Requesting changes records the response, makes the publication view-only, clones the complete revision and private media, and moves a non-won Opportunity to Quoting.
- The first recipient delivery or generic-link share moves an eligible Opportunity to Presented. Acceptance moves it to Won. A won Opportunity is never moved backward by a later response.

## Acceptance and expiration

Recipient links may accept using their assigned email without a challenge. Generic links, or a recipient using another email, require a short-lived rate-limited verification code. Acceptance requires typed name, title, email, explicit consent, and a nonblank bounded PNG signature.

The acceptance transaction locks and revalidates the publication, recalculates selected options, freezes the complete accepted snapshot and totals, allocates the frozen payment schedule exactly, stores immutable signature evidence on the private disk, records one acceptance under unique constraints, and changes the Opportunity to Won. Idempotency tokens make retries converge on the existing acceptance.

Expired proposals are view-only. An authorized Super Admin may extend an unaccepted active or expired publication only after explicit Catalog price review. Any current Catalog price difference requires a new revision rather than mutating the publication.

## Preservation and rollback

The retained database was inventoried before migration. A recoverable SQLite backup was created and restore-verified at `storage/app/backups/ops-commercial-phase5-pre.sqlite` with SHA-256 `024AFA6D092A0FFACC87847B10471F39A82E0660A7AC8823C7E3DE714626F225`. The active `.env` was not changed.

Rollback removes only Phase 5 tables and columns. Because acceptance evidence is immutable business history, rollback after real acceptance requires owner-approved recovery from the retained backup.

## Validation

Local validation completed on 2026-08-28:

- Commercial Operations: 30 tests and 282 assertions passed.
- Complete PHPUnit: 463 tests and 4,052 assertions passed.
- Pint check passed; 213 compiled Blade files passed PHP syntax lint.
- Vite production build passed; Composer strict validation and security audit passed.
- Isolated beta setup and validation passed with exact fixture counts; 8 Beta Hardening tests and 56 assertions passed.
- Local benchmark p95 results remained inside budget: Dashboard 15.3 ms, Today 9.1 ms, Dispatch 20.6 ms, Projects 13.5 ms, Customer detail 13.2 ms, Project detail 17.8 ms, Ticket detail 19.2 ms, Review detail 12.8 ms, and media first byte 0.1 ms.
- Migration rollback and reapply passed on an isolated copy. `git diff --check` passed.

The acceptance tests explicitly confirm that no Invoice or payment transaction is created. The local Playwright browser process encountered a workstation Chromium target crash after the beta fixture passed; the PR’s clean GitHub browser job is the authoritative Playwright/axe gate.
