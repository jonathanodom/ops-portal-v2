# Commercial Operations V1 — Phase 9 Release-Readiness Record

## Status

Phase 9 is a stabilization and owner-acceptance gate. It does not authorize merge, deployment, production mail, customer contact, or payment processing.

- Branch: `feat/commercial-operations-v1-phase-9-stabilization`
- Starting and preceding accepted SHA: `9719e4b30881155a3eaf39cfddaaac25ef95ac8f`
- Phase 8 draft PR: #55
- Phase 7 draft PR: #54
- Phase 6 draft PR: #53
- Final owner acceptance: pending

## Phase 0–9 implementation map

| Phase | Delivered boundary |
| --- | --- |
| 0 | Commercial module boundary, capability contracts, organization settings, numbering, and shared conventions |
| 1 | Organization-scoped Opportunity pipeline, lifecycle, activity, tasks, attachments, and ownership |
| 2 | Revisioned Quote documents, hierarchy, Catalog snapshots, allowances, packages, options, and payment schedules |
| 3 | Deterministic estimating defaults, labor/cost resolution, decimal and USD form normalization |
| 4 | Approval policy, immutable publication snapshots, private media/PDF, delivery, and Proposal library |
| 5 | Token customer presentation, engagement, comments, change requests, email verification, signature, and immutable acceptance |
| 6 | Idempotent accepted-schedule deposit Invoice draft generation without issue or payment |
| 7 | Accepted-scope Project conversion, templates, selected Service Tickets, material/labor plans, and milestone draft billing |
| 8 | Project Change Orders, signed deltas, negative approval, allowance resolution planning, and idempotent scope application |
| 9 | Recovery rehearsal, queue drain/retry validation, bounded query regression, full platform validation, and owner gate |

## Schema and retained-data boundary

Commercial migrations are additive and remain immutable:

- `2026_08_27_010000_create_commercial_opportunity_foundation`
- `2026_08_27_020000_seed_commercial_opportunity_capabilities`
- `2026_08_27_030000_create_commercial_quote_foundation`
- `2026_08_27_040000_seed_commercial_quote_capabilities`
- `2026_08_27_050000_add_commercial_estimating_defaults`
- `2026_08_28_010000_create_commercial_approval_publication_foundation`
- `2026_08_28_020000_seed_commercial_publication_capabilities`
- `2026_08_28_030000_create_commercial_customer_response_acceptance`
- `2026_08_28_040000_add_deposit_invoice_provenance_to_accepted_payment_milestones`
- `2026_08_28_050000_create_commercial_project_conversion_foundation`
- `2026_08_28_060000_create_commercial_change_order_foundation`
- `2026_08_28_061000_create_project_allowance_resolutions`

No Phase 9 migration or private object mutation is introduced. The retained local database contained 1 Opportunity, 2 commercial documents, 3 publications, 1 acceptance, and 1 Project commercial scope before stabilization. The private-storage manifest contained 768 objects totaling 19,865,969 bytes.

The verified pre-Phase 9 SQLite backup is `storage/app/backups/ops-commercial-phase9-pre.sqlite`, SHA-256 `D90D96F80EE82CDD17DC6A8525DF82B9912757085F4D4EEDF002B22A78A635F5`. Its manifest verified 120 tables, migration state, table counts, key relationships, and representative workflows on an isolated restore target.

Once a publication, signature, acceptance, conversion, or Invoice exists, rollback is a recovery operation rather than a routine migration downgrade. Preserve database and private storage together, verify the restored target in isolation, and retain immutable evidence before any owner-approved rollback.

## Security and privacy review

- Every commercial read and write remains scoped through the active organization and capability-aware policy.
- Explicit capability denials and inactive memberships remain authoritative, including Super Admin denials.
- Customer tokens are high entropy; only SHA-256 hashes are stored. Public routes are rate limited and use no-store/no-index protections.
- Publications, acceptances, signatures, Catalog snapshots, Project scope mappings, and issued Invoice history remain immutable.
- Private media and PDFs use opaque private-disk keys and authorized streaming.
- Audit and incident metadata contain identifiers, state names, safe codes, and changed field names—not customer narratives, contact data, signatures, tokens, storage keys, or provider secrets.
- Financial calculations continue using integer cents, quantity thousandths, basis points, deterministic allocation, and half-up cent rounding.
- Change Order acceptance cannot mutate a Project until manager application, and negative changes never create an automatic refund or alter an issued Invoice.

## Queue and recovery behavior

Local development uses the database queue and log mailer. `composer dev` starts the server, queue listener, logs, and Vite together. If components are started separately, run `php artisan queue:work`; a persistent supervised worker is required outside local development.

The retained local queue was drained with:

```powershell
php artisan queue:work --stop-when-empty --tries=2 --timeout=120
```

Result: 91 queued jobs processed, 0 remaining, 0 failed; Proposal PDFs moved from 1 pending/2 ready to 0 pending/3 ready. No external mail was sent because the local mailer is `log`.

Jobs for delivery, reminders, PDF rendering, notifications, deposit reconciliation, conversion-related work, and private-object cleanup retain idempotency or state guards. Staff-visible failures remain bounded and recoverable through existing retry/reconciliation controls and operational incidents.

## Query and performance boundaries

Full-request query ceilings include organization middleware and shared Office navigation:

| Surface | Maximum queries |
| --- | ---: |
| Opportunity pipeline | 65 |
| Opportunity detail | 80 |
| Quote/Change Order editor | 85 |
| Publication review | 75 |
| Customer Proposal | 50 |
| Project scope detail | 95 |

The beta benchmark continues to enforce existing warm p95 and query ceilings for Office Dashboard, Field Today, Dispatch, Projects, Customer, Service Ticket, Closeout Review, and private-media first byte. Phase 9 query tests guard the Commercial surfaces against unbounded growth and N+1 regressions.

## Validation record

Focused Phase 9 checks:

- Commercial read budgets: passed, 15 assertions.
- Change Order and Project scope budgets: passed within the existing Change Order scenario, 32 assertions total.
- Verified backup/restore: passed; 120 tables and representative relationships matched.
- Queue drain/recovery: passed; 91 processed, 0 remaining, 0 failed, 3 PDFs ready.

Final local validation:

- Full PHPUnit: 467 passed, 4,122 assertions.
- Composer validation and security audit: passed; no advisories.
- Pint: passed after one mechanical import-order correction.
- Compiled Blade syntax: 210 files passed.
- Vite production build: passed.
- Isolated beta fixture: passed with 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, 500 private-media records, and SQLite integrity `ok`.
- Beta hardening: 8 passed, 56 assertions.
- Beta benchmark: all p95/query budgets passed. Office Dashboard 17.9 ms/31 queries; Today 10.8/9; Dispatch 24.0/15; Projects 13.9/16; Customer detail 17.0/21; Project detail 25.5/28; Ticket detail 18.6/27; Review detail 14.4/29; private-media first byte 0.1 ms.
- Playwright/axe: 30 passed, 26 intentionally skipped by project/viewport guards, 0 failed, 3.2 minutes. The Commercial test exercised 390, 768, 1280, 1440, and 1920px with no serious/critical axe findings or horizontal overflow.
- Commercial screenshot artifacts: 10 files under `docs/ui-review/commercial-phase9`, covering Proposal library and approval queue at all five required widths.
- `git diff --check`: passed.

The GitHub MySQL 8.4 workflow result is recorded in the Phase 9 PR after workflow dispatch. A failed or unavailable required GitHub check remains a blocker to final owner acceptance.

## Local non-destructive run instructions

```powershell
composer phase:update
composer dev
```

`composer phase:update` applies additive migrations and idempotent capability seeding without replacing `.env` or retained records. `composer dev` starts the local HTTP server, queue listener, log viewer, and Vite. Local/test Proposal delivery is written to `storage/logs/laravel.log`; it is not sent externally.

Useful routes for a Super Admin:

- `/office/opportunities`
- `/office/commercial-library`
- `/office/quote-approvals`
- `/office/projects`
- `/office/billing`

## Required owner acceptance scenario

1. Create and qualify an Opportunity.
2. Build a Quote with Products, Services, a tailored Package, an Allowance, Locations, Systems, Phases, discount/tax, options, and payment milestones.
3. Trigger and resolve a pricing approval.
4. Publish and add a recipient using local/test delivery; obtain the secure link from the Office publication or local log.
5. Open recipient and generic links, comment, request changes, publish a new revision, and verify the earlier publication is unchanged.
6. Select an option and accept with verified email, typed identity/title, consent, and drawn signature.
7. Review the resulting draft deposit Invoice without issuing or charging it.
8. Convert the accepted scope through a Project template, map scope, create selected Service Tickets, and inspect material/labor plans and milestones.
9. Complete a mapped Project milestone and verify exactly one draft milestone Invoice.
10. Create, approve, publish, accept, and apply both a positive Change Order and a Super Admin-approved negative Change Order.
11. Confirm the original accepted Proposal and prior Project scope remain immutable.
12. Smoke-test Field, Closeout, Review, Billing Handoff, Invoice, and Payment workflows using retained local data.

## Known limitations and deferred work

- Local mail is intentionally log-only; production mail configuration and customer contact require separate owner approval.
- A persistent queue worker is an environment requirement; jobs remain pending when no worker runs.
- Phase 9 does not merge, deploy, enable production payments, or authorize production cutover.
- Generalized import/export, proposals beyond Commercial V1, inventory, accounting synchronization, and later product phases remain out of scope.
- Browser screenshots are validation artifacts, not production evidence, and contain synthetic beta data only.

## Gate

`WAITING FOR FINAL OWNER ACCEPTANCE — PHASE 9`
