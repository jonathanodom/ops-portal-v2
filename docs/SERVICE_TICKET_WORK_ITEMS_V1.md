# Service Ticket Work Items V1

## Baseline and dependency

This feature branches from `main` at `8795ea056f1518fbcbc5d1ad90f4f5afc5c326ed`, the merge commit for PR #43, **Add Super Admin submitted Visit time corrections**. The raw/effective Visit-time model, immutable submitted correction history, exact Super Admin correction gate, overlap diagnostics, and post-approval correction stop remain unchanged.

## Scope model

The Service Ticket remains the primary scope. Its title, description, and customer-visible summary are not duplicated into a Work Item.

`ServiceTicketWorkItem` represents additional discrete work discovered or added while fulfilling that primary scope. Origins are `field_discovered` and `office_added`. Statuses are:

- `open`
- `completed`
- `needs_follow_up`
- `transferred`
- `canceled`

Open and needs-follow-up items block Service Ticket completion. Completed, transferred, and canceled items are terminal.

## Visit provenance

`discovered_visit_id` records the Visit where Field first discovered an item. The `service_ticket_work_item_visit` pivot records every Visit where the item was handled, including first/last actor and timestamp attribution. Its unique Work Item/Visit key makes repeated saves update the existing touch instead of duplicating provenance.

Work Items belong to the Ticket rather than a Closeout version. Returning a Closeout for correction therefore retains the same Work Items and touch history without copying them into v2 or later versions.

## Field rules

Existing Visit execution authorization remains authoritative. Field creation or disposition requires:

1. An execution-authorized actor.
2. A nonarchived Visit in `on_site` or `returned_for_correction`.
3. A current draft Closeout.
4. An open or on-hold parent Service Ticket.

Field creates an open item, immediately touches the current Visit, and may later select Open, Completed, or Needs follow-up while updating the work note. Field cannot cancel or transfer an item and cannot edit its title or detail after creation. Transferred items are read-only.

## Closeout readiness and Ticket completion

An open Work Item touched on the current Visit blocks Closeout submission until the technician selects Completed or Needs follow-up. An Office-added open item untouched on that Visit—or an item touched only on a different Visit—does not block that Visit's submission.

Visit approval remains independent: Review may approve the Visit while unresolved Ticket Work Items keep the Service Ticket open. No Billing Handoff is created while any Work Item is Open or Needs follow-up. When Office moves the final blocker to Completed, Canceled, or Transferred, the shared `ServiceTicketCompletion` service re-evaluates normal completion and handoff eligibility.

## Follow-up Service Tickets

Office users with the existing dispatch authority may transfer a Needs follow-up item into a canonical follow-up Service Ticket. The transaction locks the Work Item, inherits Organization, Customer, Service Location, and Contact, requires the new Ticket's existing billing disposition, uses `source=internal`, creates no Visit, stores the follow-up relationship, and re-evaluates the original Ticket.

The Work Item links forward to the generated Ticket, and the generated Ticket links back to its source Ticket Work Item. Repeated transfer requests return the same generated Ticket rather than allocating another Ticket number.

## Authorization and tenant boundaries

No new capability is introduced:

- Office reads use `service_tickets.view`.
- Office mutations and transfer use the existing Service Ticket update/`dispatch.manage` authority.
- Field reads and mutations use existing Visit inspection/execution policies.
- Review uses existing closeout inspection authorization and remains read-only.

Controllers resolve Ticket, Visit, and Work Item identifiers inside the active Organization and return 404 with the established safe security audit for cross-organization identifiers. Workflow guards require matching Ticket and Visit context.

## Operational presentation

- Field Visit shows primary scope separately from additional Work Items and provides phone-friendly add/disposition controls.
- Office Service Ticket shows origin, discovered Visit, touched Visits, status, and transfer provenance.
- Closeout Review shows only Work Items handled on that Visit and explains unresolved Ticket blockers.
- NewDay Home includes at most three needs-follow-up candidates in the existing bounded Service Operations attention feed.
- The Service Work Order includes a compact Additional Work Items section without Audit history.

## Billing boundary

Work Items have no commercial fields. This feature adds no invoice lines, rates, pricing, parts, media, files, per-item labor, or time allocation. When an item becomes a separate follow-up Service Ticket, that Ticket's normal `billing_disposition` is the only commercial choice.

## Purge, archive, and audit safety

The guarded Service Ticket purge inventory includes owning Work Items and Visit-touch pivots and removes them before Visit/Ticket deletion. A Work Item on another Ticket that points to the purge target as its follow-up is an external business reference and blocks the purge.

Soft-archiving a Visit retains Work Item provenance. Permanent Visit deletion is blocked when the Visit is a discovery or touch source. Foreign keys remain enabled.

Work Item Audit Events contain only IDs, origin, state names, and changed field names. Detail and work-note bodies are never placed in Audit metadata.

## Validation

Validation completed against the retained local SQLite database and isolated beta database:

- Focused Work Items: 8 tests, 64 assertions.
- Full PHPUnit: 401 tests, 3,315 assertions, using PHP's 512 MB process limit. The default local 128 MB limit exhausted memory late in the otherwise passing suite.
- Destructive-purge regressions: 7 tests, 58 assertions with `FIELD_TEST_DESTRUCTIVE_PURGE_ENABLED=false`, matching the repository default. The developer's local `.env` intentionally enables that guarded field-test tool.
- Retained database backup: `storage/app/backups/pre-service-ticket-work-items-v1.sqlite`, SHA-256 `115dc68724e421bee3521dd5eaf1db2bb09ea7aaeb6de8af0fe9d0f837ae1bc1`; isolated restore verification passed with SQLite integrity OK, 69 tables, and matching migration, count, relationship, and representative workflow checks.
- Additive migration: fresh migration, one-step rollback, reapply, retained-database migration, and idempotent capability seed passed.
- Beta fixtures: exact 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, 500 media records, 2 Projects, 10 Workstreams, 4 Tasks, 2 Milestones, and 3 scenarios; beta hardening passed 8 tests and 56 assertions.
- Local benchmark, 10 warm runs: Home 14.3 ms/31 queries, Today 8.9/9, Dispatch 19.5/15, Projects 13.0/16, Customer detail 11.1/21, Project detail 20.0/25, Ticket detail 14.1/27, Review detail 14.6/27, and authorized media first byte 0.1 ms.
- Browser/accessibility: 27 passed and 23 intentionally skipped across the beta matrix. Work Item Office, Review, and Field coverage passed at 390, 768, 1280, 1440, and 1920 pixels with no horizontal overflow or serious/critical axe violations.
- Pint, Composer validation/audit, compiled-Blade lint (186 files), Vite production build, and `git diff --check` passed.

The merged dependency baseline's GitHub Actions run also passed before this branch was created.

## Future PR C seam

Time attribution remains intentionally absent. A future migration can add nullable `work_item_id` attribution to time entries with this convention:

```text
work_item_id = null  -> primary Service Ticket scope
work_item_id = N     -> additional Work Item
```

PR C must preserve the Work Item and Visit provenance established here and independently define timer-switching and commercial behavior.
