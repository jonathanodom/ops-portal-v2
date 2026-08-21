# NewDay Home V1

## Baseline and purpose

NewDay Home V1 branches from `main` at `ea8c094faafca8e2d7bf282ec7d4cff74d17b576`. The latest GitHub Actions validation on that baseline was green.

`GET /office` is the internal command center for answering “What needs my attention today?” It remains a read-only composition surface: users follow links into the authoritative workspace to perform work.

## Architecture and ownership

Home remains inside the existing Laravel modular monolith and database.

- `OfficeDashboardSnapshot` remains the source for Service Operations, Visit, Closeout, Billing, Invoice, and health summaries. Home does not redefine those rules.
- `ProjectHomeSummaryQuery` owns the Projects read projection for active Projects, due/overdue/blocked Tasks, and incomplete Milestones in the next 30 days.
- `NewDayHomeSnapshot` composes those projections, capability-aware workspace launchers, and a deterministic attention feed capped at 12 items.
- `CustomerDirectorySearchQuery` searches canonical Customer, Contact, and Service Location records in process. It does not live in Projects and creates no duplicate records.

Portal continues to own Customers, Service Operations, Field, Closeout, Billing, Invoice, Payment, and Catalog data. Projects continues to own Projects, Workstreams, Tasks, Milestones, notes, attachments, and Service Ticket relationships. Home owns no transactional business records.

## Needs Attention

The feed is a transient read projection. It orders candidates as follows:

1. overdue invoices;
2. overdue Project Tasks;
3. blocked Project Tasks;
4. operational follow-up, submitted Closeouts, and ready Billing Handoffs;
5. upcoming Project Milestones.

Ordering inside a group is deterministic by relevant date, kind, and source identifier. The first 12 items render, and every item links to its owning Office workspace. Home exposes no mutation action for an attention item.

## Customer Directory search

`GET /office/search?q=...` requires Office access and `customers.view`. It searches Customers, Contacts, and Service Locations only.

- Queries are trimmed and require at least two characters.
- SQL LIKE wildcard characters are escaped.
- Each result group is independently limited to eight and deterministically ordered.
- Contact results include and link to their owning Customer; Location results include their owning Customer and link to the canonical Location.
- Active and inactive records remain discoverable. Inactive records are visibly labeled to preserve historical discoverability.

Service Tickets, Projects, invoices, and Catalog records are intentionally outside the V1 directory scope.

## Authorization and tenant isolation

Composition skips data when the active membership lacks the corresponding capability:

- `service_tickets.view`: Service Operations launcher and Portal operational projections.
- `projects.view`: Projects launcher, counts, and attention candidates.
- `customers.view`: Home search and the search route.
- `closeouts.inspect`: submitted Closeout candidates.
- `billing_handoffs.view`: ready Billing Handoff candidates.
- `invoices.view`: invoice and A/R candidates.
- `operations.health.view`: operational health.

Every source query is scoped to the active Organization. Focused tests include similarly named records in another Organization and explicit capability denials.

## Performance bounds

Counts use aggregate queries. Supporting lists are capped at six per Projects source, three per Closeout/Handoff source, existing bounded Portal sources, eight per directory entity, and 12 overall attention items. Required relationships are eager loaded. Directory queries do not run from Home and do not run on the results route until the minimum query length is met. No cache or external search infrastructure was added.

## Validation

Focused tests cover Project local-date semantics, terminal exclusions, tenant isolation, capability-aware launchers and hidden sections, deterministic attention ordering and bounds, directory search fields, wildcard escaping, grouped canonical links, inactive labeling, and route authorization. The existing `OfficeDashboardSnapshot` characterization coverage continues to protect Portal counts, Today, Review, Billing Handoff, A/R, follow-up, and health semantics.

Final PHPUnit, formatting, dependency, Blade, frontend, beta, benchmark, Playwright/axe, and diff results are recorded in the draft PR.

The retained local beta fixture benchmark recorded NewDay Home at p95 `14.2 ms` and `29` queries across 10 warm runs. Its guarded query ceiling is `32`; the response-time budget remains `500 ms`.

## Explicit non-goals

This work adds no schema, API, separate runtime, duplicated business record, inline mutation, Staff Time Tracking, Sales, leads, proposals, Inventory, Finance/accounting, customer portal, universal search, external search engine, notification center, live refresh, or customizable dashboard.

After Home V1, the next major product decision is Sales **or** Inventory based on real field evidence—not both by default.
