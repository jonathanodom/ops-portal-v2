# Field-Test Service Ticket Purge

## Purpose and baseline

This narrowly scoped utility permanently removes one obsolete field-test Service Ticket aggregate so the scenario can be recreated through the current workflow. It is not ordinary production deletion and it does not replace archive, cancellation, invoice void/reissue, payment reversal, or historical retention.

Implementation branched from `main` at `a1785c7a219fe7cd5c615aba47766a540721ff05`. Local `main`, `origin/main`, and GitHub matched, and GitHub Actions was green before implementation.

## Required safety gates

Every gate is required:

1. `FIELD_TEST_DESTRUCTIVE_PURGE_ENABLED=true`. The default and `.env.example` value are `false`.
2. The active Organization Membership has the exact role key `super_admin`.
3. The same membership has the effective `service_tickets.purge_test_data` capability. An explicit denial wins.
4. The Service Ticket belongs to the active Organization.
5. The operator types the exact current Service Ticket number.
6. The operator accepts the permanent-destruction acknowledgment.

When the flag is false, the Ticket action is absent and both direct purge routes return 404. A non-Super-Admin remains denied even if the capability is explicitly granted. There is no normal Service Ticket `DELETE` route and no bulk operation.

## Dependency graph

The migration-defined ownership and reference paths are:

- Service Ticket → notes, reopen records, Project pivot links, files, Visits, Billing Handoff, and Invoices.
- Visit → assignments, time entries, media, part proposals, Closeouts, return-Visit lineage, and its current-Closeout pointer. Active and soft-deleted Visits are included.
- Closeout → version lineage, return-Visit reference, time entries, media, proposals, Reviews, reviewer adjustments, and reviewed trip charges.
- Billing Handoff → current Invoice pointer and Invoice generations.
- Invoice → lines, approved-Closeout links, acknowledgments, Payment Attempts, Payment Transactions, Payment Receipts, PDF metadata, and reissue lineage.
- Payment Transaction → original transaction for refunds/reversals.
- Audit Events and Operational Incidents → polymorphic subjects and safe metadata/context identifiers that may reference any purged record.

External Invoices that are not owned by the Ticket but reference its Visit, Closeout, Review, time-entry, or proposal provenance block the purge. The workflow never deletes an unrelated Invoice to satisfy an FK.

## FK-safe relational deletion order

Foreign keys remain enabled. The workflow locks the Ticket, rebuilds the graph, and executes one database transaction in this order:

1. Referencing Audit Events and Operational Incidents.
2. Payment Receipt metadata.
3. Payment Transaction refund/reversal self-references, then transactions.
4. Payment Attempts.
5. Invoice acknowledgments, approved-Closeout links, and lines.
6. Billing Handoff current-Invoice pointers and Invoice reissue references.
7. Invoices, then Billing Handoffs.
8. Review adjustments and trip charges, then Reviews.
9. Visit current-Closeout/return links, Closeout version/return links, and proposal lineage.
10. Time entries, media, proposals, Closeouts, assignments, and physical deletion of active and soft-deleted Visits.
11. Ticket files, notes, reopen records, and Project pivot rows.
12. The Service Ticket, last.

Any relational failure rolls back the entire transaction. Child identifiers are always derived server-side; request-supplied child IDs have no authority.

## Private storage cleanup

Before relational deletion, the workflow captures an encrypted manifest containing:

- Service Ticket file disk/key pairs.
- Visit media disk/key pairs.
- Invoice PDF disk/key pairs.
- Payment Receipt PDF disk/key pairs.

After the database commit, objects are synchronously deleted and their absence is verified. An already-missing object is considered clean. A failed deletion is surfaced as **Database purge complete; private storage cleanup is incomplete**. Minimal non-domain cleanup state retains only the Organization, actor, encrypted manifest, counts, status, and retry attempts. It does not retain the deleted Ticket identifier or expose storage keys in the UI.

## Provider and Project boundaries

The purge never calls Square, Stripe, or another payment provider. Removing Portal test records does not refund or reverse an external transaction, and the confirmation screen states this explicitly.

Only `project_service_ticket` links are deleted. Projects, Workstreams, Tasks, Milestones, Project Notes, and Project Attachments remain intact. Customer, Contact, Service Location, users, Organization settings, payment-provider configuration, Catalog data, and unrelated operational records also remain intact.

## Audit and application logging

Audit Events whose subject or metadata identifies the Ticket aggregate are deleted. Operational Incidents with matching subjects or safe context identifiers are also deleted. The purge does not write a new domain Audit Event containing the deleted Ticket ID.

Application logs contain only Organization ID, actor ID, aggregate counts, cleanup ID where needed, and a safe failure class. They omit customer text, notes, filenames, payment secrets, and storage locations.

## Production-disable checklist

Before final real-data production use:

- Set `FIELD_TEST_DESTRUCTIVE_PURGE_ENABLED=false`.
- Rebuild the Laravel configuration cache.
- Confirm the Field Testing Tools panel is absent.
- Confirm direct preview and POST routes return 404.

No code removal is required to disable the utility.

## Validation

Focused automated coverage verifies default-off behavior, exact-role/capability authorization, explicit denial, tenant isolation, typed confirmation, irreversible acknowledgment, server-derived child IDs, repeat-request safety, a dirty operational/financial aggregate, physical removal of archived Visits, private-object deletion, missing-object idempotency, recoverable storage failure, provider-call absence, preservation boundaries, and complete relational rollback.

Local final results:

- Focused purge suite: 6 tests, 52 assertions.
- Full PHPUnit: 366 tests, 3,021 assertions.
- Related Ticket/Visit/Closeout/Invoice/Payment/Project regression group: 155 tests, 1,334 assertions.
- Composer strict validation and security audit: passed, no advisories.
- Pint and `git diff --check`: passed.
- Compiled Blade lint: 177 templates passed.
- Vite production build: passed.
- Isolated beta validation: exact fixture counts passed; queue/detail/media benchmarks remained within budget.
- Playwright/axe responsive suite: 22 passed and 18 expected project/viewport skips across desktop and 390×844 mobile projects.
- Manual responsive confirmation-screen review: 390, 768, 1280, 1440, and 1920 px; no horizontal overflow and all primary input/action targets measured at least 44 px.

GitHub Actions results are recorded in the draft PR after publication.
