# Commercial Operations V1 — Phase 0 Preservation Report

## Gate

Phase 0 is characterization and preservation only. It adds no Commercial Operations schema, models, routes, capabilities, navigation, or user interface. Phase 1 must not begin until the owner accepts this checkpoint.

## Baseline

- Branch: `feat/commercial-operations-v1-checkpoints-0-3`
- Starting `main` SHA: `02e789781376e1e8236893f78ccc04d939c7efb0`
- Starting GitHub Actions run: `33100854089` (green)
- Deployed field-test SHA: requires owner confirmation; no repository-controlled deployment record identifies it conclusively.
- Applied migrations: 48
- Tables: 75
- Commercial Operations tables, routes, migrations, and capabilities at baseline and checkpoint end: none

The uploaded architecture and Phase 0–3 handoff are checked into `docs/` as the authoritative Commercial Operations references. The repository README links them and states the Phase 0 acceptance lock.

## Characterization Coverage

The checkpoint strengthens preservation tests around the existing seams Commercial Operations will eventually consume:

- Catalog-backed invoice lines retain immutable service, variant, unit-of-measure, quantity, price, and source snapshots after Catalog records change.
- A Project created through the canonical Office route can create and link a canonical Service Ticket while preserving both document sequences and safe audit attribution.
- Direct Invoices retain their Customer and Service Location context without creating a Billing Handoff.
- Invoice discount allocation and taxable-line calculations use stable integer-cent results.
- Closeout signatures use opaque private-storage keys, retain hash integrity, require scoped authorized delivery, and avoid sensitive audit metadata.
- Explicit `visits.execute_any` use retains actor attribution and safe audit metadata.
- Phase 0 contains no accidental Commercial Operations application surface.

The base test case now fakes the private local disk for feature tests. This closes an existing isolation gap that allowed PDF jobs exercised by tests to touch retained local private storage. PHPUnit receives a 512 MB memory limit so the full Windows suite can run through the repository Composer wrapper.

## Retained Local Data

The retained SQLite database and private object store were inventoried before and after validation.

| Record group | Before | After |
| --- | ---: | ---: |
| Organizations / users / memberships | 1 / 1 / 1 | 1 / 1 / 1 |
| Customers / contacts / locations | 8 / 8 / 10 | 8 / 8 / 10 |
| Catalog categories / services / variants / products / packages | 3 / 8 / 4 / 5 / 1 | 3 / 8 / 4 / 5 / 1 |
| Projects / workstreams / tasks / milestones | 2 / 3 / 4 / 2 | 2 / 3 / 4 / 2 |
| Service Tickets / Visits / Closeouts / Reviews | 12 / 13 / 11 / 5 | 12 / 13 / 11 / 5 |
| Billing Handoffs / Invoices / Invoice lines | 3 / 13 / 15 | 3 / 13 / 15 |
| Payment transactions / receipts | 3 / 3 | 3 / 3 |
| Audit events | 177 | 177 |
| Private objects / bytes | 750 / 19,359,009 | 750 / 19,359,009 |

The initial database file was 1,904,640 bytes with SHA-256 `94817A04D022503E14284BAE6DDE087131766BBC836C008FCB6B5189CB33FA15`. The ending file remains 1,904,640 bytes; its physical SQLite SHA-256 is `6C7F351D0AC8AD2CE9D66AD2C179C80929035B13E4EF9B1C2BD28B113971E4E3`. All 75 table counts and representative operational relationships remained unchanged. The physical checksum changed after local SQLite activity even though the logical inventory did not.

An initial validation attempt exposed four unreferenced test-generated receipt PDFs in retained private storage. Their absence from every database reference was verified, the exact four test objects were removed, and the original 750-object/19,359,009-byte manifest was restored. The new global private-disk fake prevented recurrence through the focused and full suites.

## Validation Results

- Pre-change direct PHPUnit baseline: 432 tests, 3,742 assertions; passed in 127.758 seconds; 124 MB peak.
- Focused Commercial Operations characterization: 87 tests, 929 assertions; passed in 25.986 seconds; 96 MB peak.
- Post-change direct PHPUnit regression: 434 tests, 3,774 assertions; passed in 120.238 seconds; 124 MB peak.
- `composer check`: passed Composer validation, Pint, 195 compiled-Blade syntax checks, 434 tests / 3,774 assertions, and Vite production build.
- `composer audit --locked --no-interaction`: no security vulnerability advisories.
- `git diff --check`: passed.
- GitHub Actions run `33121151297`: passed on implementation commit `d851f32` (`core` 3m01s, `safety` 1m32s, `browser` 5m01s, aggregate `validate` 3s).

## Owner UI Acceptance Checklist

No Phase 0 UI changed. Use the retained local organization and Super Admin to confirm that existing behavior remains unchanged:

- Office Home and Customer directory open normally.
- Catalog services, variants, products, packages, and snapshots remain readable.
- Projects and their canonical Service Ticket links remain intact.
- Service Tickets, Dispatch, Review, and existing Invoice flows remain intact.
- Direct Invoice creation still works without a Billing Handoff.
- Field Today and Visit workspaces retain execution, closeout, signature, and private-media behavior.
- Billing, payment, receipt, and private document authorization remain intact.
- No Commercial Operations navigation item or unfinished surface is visible.

Local startup remains the existing workflow: keep `.env` unchanged, run `composer phase:update` if dependencies need refresh, and use `composer dev`. Phase 0 adds no migration and requires no data setup.

## Deferred Scope

Phase 1+ schema, Commercial Engagements, Estimates, Proposals, Change Orders, acceptance flows, document generation, conversion workflows, and any Commercial UI remain intentionally unimplemented.

**WAITING FOR OWNER UI ACCEPTANCE — PHASE 0**
