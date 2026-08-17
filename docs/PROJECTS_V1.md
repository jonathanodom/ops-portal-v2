# Projects V1 — Modular-Monolith Delivery Record

## Baseline and scope

- Rebuild branch: `feat/projects-v1-rebuild`
- Authoritative starting commit: `af954de9e3dbaa5508e097c0c36021781ce83170`
- Starting GitHub Actions run: `31858089484` (green)
- The pre-existing `feat/projects-v1` work was retained unchanged as reference and was not cherry-picked or force-pushed.
- Projects is an in-process bounded context inside the existing Laravel application and database. It owns Projects, Workstreams, Tasks, Milestones, notes, activity presentation, and Project-to-Service-Ticket links.
- Customers, Locations, Contacts, Service Tickets, Visits, Closeouts, Billing Handoffs, invoices, payments, and Catalog data remain canonical in their existing Portal domains.

## Additive schema

| Table | Purpose |
| --- | --- |
| `projects` | Organization-owned Project identity, immutable number, optional customer context, owner, dates, and lifecycle |
| `project_workstreams` | Ordered operational groupings within a Project |
| `project_tasks` | Assigned, prioritized, date-aware operational work |
| `project_milestones` | Ordered target/completion checkpoints |
| `project_notes` | Append-only internal notes |
| `project_service_ticket` | Unique, attributed Project-to-canonical-Service-Ticket relationship |

Project numbers use the existing locked `document_sequences` mechanism with organization/year/type isolation and the format `NDT-PRJ-YYYY-####`. No soft-delete or hard-delete route is introduced.

Rollback removes only the six Projects-owned tables and Projects capabilities. It does not alter canonical Customer, Service Ticket, Visit, closeout, invoice, payment, or Catalog records. Production rollback must follow the normal backup-and-restore runbook before applying the migration `down()` methods.

## Capability matrix

| Role | View | Manage Project | Manage Tasks/Notes | Administer Ticket Links |
| --- | ---: | ---: | ---: | ---: |
| Super Admin | Yes | Yes | Yes | Yes |
| Dispatcher | Yes | Yes | Yes | Yes |
| Reviewer | Yes | No | No | No |
| Billing | Yes | No | No | No |
| Technician | No | No | No | No |

The keys are `projects.view`, `projects.manage`, `projects.tasks.manage`, and `projects.admin`. Active-membership requirements and explicit capability overrides remain authoritative.

## Domain boundary

Projects code reads cross-domain master data only through immutable, organization-scoped projections:

- `CustomerDirectory` resolves/searches Customers and supplies valid Locations and Contacts.
- `ServiceOperationsDirectory` resolves/list compact Service Ticket projections.

Projects controllers and views do not own or mutate those records. Ticket linking validates organization and customer identity. Location-specific mismatch links require explicit confirmation and retain a visible warning. Ticket lifecycle state is never changed by Projects.

Projects activity uses the existing `AuditRecorder`. Child IDs, changed field names, and state names are recorded against the Project subject; note bodies and descriptive field contents are excluded from audit metadata.

## Lifecycle rules

- Project types: installation project, ongoing support, consulting/engineering, and internal.
- Project states: planning, active, on hold, completed, and canceled.
- Customer is required except for internal Projects. Ongoing-support Projects may remain indefinite with no target end date.
- Completing a Project records `completed_at`; an authorized correction away from completed clears it.
- Completed and canceled Projects reject new operational changes.
- Task blocking requires a reason. Completion records `completed_at`; reopening clears it. Canceled Tasks remain visible.
- Owners and assignees must be active members of the same organization.
- Overdue calculations use the Organization-local calendar date.

## Beta fixtures and performance

The beta-only, idempotent fixture adds:

- `ABC Dental — Network & AV Upgrade`
- `Trip Hopper — IT Support`
- 10 Workstreams, 4 representative Tasks, 2 Milestones, an internal note, and related canonical Service Tickets

It reuses beta volume Customers so the established 250-Customer fixture total remains unchanged. `DatabaseSeeder` and normal `phase:update` do not create demo Projects.

The beta benchmark includes `projects_workspace` (500 ms / 25-query budget) and `project_detail` (750 ms / 30-query budget). Wall-clock results are observational locally; query ceilings are enforced with `--fail-on-budget`.

## UI review

The Office workspace uses the established full-width workspace layout, responsive desktop table/mobile cards, accessible filters, organization-local attention counts, and policy-aware actions. Project detail presents Overview, Workstreams, Tasks, Milestones, related Tickets, Notes, and a bounded Activity timeline.

Review images:

- [390 px workspace](ui-review/projects-v1/workspace-390x844.png)
- [768 px workspace](ui-review/projects-v1/workspace-768x1024.png)
- [1280 px workspace](ui-review/projects-v1/workspace-1280x900.png)
- [1440 px workspace](ui-review/projects-v1/workspace-1440x900.png)
- [1920 px workspace](ui-review/projects-v1/workspace-1920x1080.png)
- [1920 px detail](ui-review/projects-v1/detail-1920x1080.png)

## Validation record

Pre-implementation baseline:

- Composer validation: passed
- Pint: passed
- compiled Blade lint: passed
- PHPUnit using isolated SQLite because Docker/MySQL was unavailable locally: 296 tests, 2,450 assertions, passed
- Vite production build: passed
- Main GitHub Actions MySQL 8.4 run: green

Projects-focused results:

- Projects feature/domain/policy/contracts: 12 tests, 65 assertions, passed
- Beta setup and exact fixture validation: passed
- Beta Projects workspace benchmark: p95 10.7 ms, 16 queries (budget 500 ms / 25 queries)
- Beta Project detail benchmark: p95 17.3 ms, 24 queries (budget 750 ms / 30 queries)
- Projects Playwright/axe at desktop and mobile: 4 tests passed
- Responsive observations: 390, 768, 1280, 1440, and 1920 px screenshots captured with no horizontal overflow
- Full PHPUnit regression using isolated SQLite: 308 tests, 2,519 assertions, passed
- Full Playwright/axe regression: 14 applicable tests passed, 10 intentionally project-specific viewport skips
- Composer validation, Pint, compiled-Blade lint (170 files), Vite production build, and `git diff --check`: passed
- Isolated beta SQLite backup/restore rehearsal: passed; 66 tables and representative relationships/workflows matched

MySQL 8.4 migrations, full regression, backup/restore, beta benchmark, and Playwright validation are repeated by GitHub Actions on the draft PR. Its result is recorded in the PR before review.

## Known limitations

- V1 has no Projects API, document subsystem, billing ownership, inventory, proposals, Gantt scheduling, hard deletion, or separate database/application.
- The activity timeline is intentionally bounded to recent Project audit events plus append-only Project notes.
- Project-to-Ticket relationships are contextual links only; operational Ticket and Visit behavior remains in the Portal.
