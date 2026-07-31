# Phase 2 validation record

## Delivered

Phase 2 adds an organization-scoped service-ticket and visit control plane without replacing local development data. The user-facing term is **Service Ticket** and the domain table is `service_tickets`; Laravel's existing `jobs` table remains reserved for database queues.

Additive schema:

- `document_sequences` — locked organization/year/type counters.
- `service_tickets` — customer request, operational priority/source/status, and immutable ticket number.
- `service_ticket_notes` — append-only office notes.
- `visits` — schedule, timezone, return-trip link, execution timestamps, and cancellation state.
- `visit_assignments` — active organization memberships with exactly one lead through the application workflow.

No Phase 0 or Phase 1 migration was edited. `composer phase:update` applies the new migration and idempotently adds `service_tickets.view`; it does not recreate tables or replace users/customer data.

## Authorization matrix

| Role | Office ticket view | Ticket/dispatch management | Field inspection | Assigned execution |
| --- | --- | --- | --- | --- |
| Super Admin | Yes | Yes | All visits | All visits through `visits.execute_any` |
| Dispatcher | Yes | Yes | All visits | No, unless explicitly granted |
| Technician | No | No | Assigned visits | Assigned visits |
| Reviewer | Read only | No | No field access | No |
| Billing | Read only | No | No field access | No |

Membership capability overrides continue to take precedence. TechnicianProfile is never consulted for access.

## Transition rules

- Ticket: `open → on_hold → open`; `open|on_hold → canceled`.
- A held ticket retains schedules but blocks En Route and On Site.
- Ticket cancellation atomically cancels all nonterminal visits.
- Visit state derives as `planned`, `scheduled`, or `assigned` from its window and assignments.
- Field execution is `assigned → en_route → on_site`.
- A return visit requires an on-site source, remains under the same ticket, and starts planned.
- Visit cancellation and ticket hold/cancellation require reasons. Sensitive reason text is not copied into audit metadata.
- Schedule overlaps warn and require explicit confirmation; confirmed conflicting visit IDs are audited.

## Validation

Local validation completed:

- `composer validate --strict` — passed.
- Additive migration and idempotent system seed — passed against the existing local database.
- Existing local records before/after migration — 1 user, 1 customer, 1 contact, and 2 locations; all preserved.
- `php artisan test` — 36 tests passed with 193 assertions.
- `vendor/bin/pint --test` — passed.
- `npm run build` — passed.
- Blade compilation and `git diff --check` — passed.
- Browser smoke test — login brand, accessible labels, password reset link, and responsive shell rendered successfully.

The pull request CI repeats migrations, seeding, tests, formatting, and the production asset build against MySQL 8.4.

Manual review targets:

- Office service-ticket creation with and without a first visit.
- Day dispatch queue, week workload strip, filters, and unscheduled backlog.
- Multiple assignees with one lead and overlap confirmation.
- Small-phone Today/upcoming queues, tap actions, En Route, and On Site.
- Location timezone labeling, keyboard navigation, empty/error/offline states.
- Same ticket viewed through authorized office and field projections.

## Rollback and exclusions

The migration rollback removes only Phase 2 tables, in dependency order. It does not touch organizations, users, customers, contacts, service locations, queue jobs, or their existing rows. Rolling back Phase 2 would remove Phase 2 ticket/visit data, so it requires an explicit backup and owner approval in any persistent environment.

Phase 2 does not include work logs, time entries, media, parts, closeouts, approvals, billing handoffs, invoices, inventory, notifications, external calendars, maps, route optimization, beta imports, deployment, or production cutover.
