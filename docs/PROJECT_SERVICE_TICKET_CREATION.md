# Projects V1.1 — Project Service Ticket Creation

## Baseline and delivery scope

- Branch: `feat/project-service-ticket-creation`
- Authoritative baseline: `0a99dbfa912f1ca682aaa0c71ee20dc79aecaee7`
- Baseline GitHub Actions run: `32176861878` (green)
- This enhancement creates an ordinary canonical Service Ticket from a customer-backed Project and links it through the existing `project_service_ticket` pivot.
- No schema migration, Project foreign key, API, queue integration, Catalog change, or Project-owned Ticket lifecycle was introduced.

## Design and domain boundary

`ServiceTicketCreator` now contains the canonical transactional Ticket creation operation used by both the normal Service Tickets workspace and Project-context creation. It retains standard numbering, default-contact selection, auditing, optional first-Visit creation, local schedule conversion, assignments, automatic single-technician lead behavior, and conflict confirmation.

`ProjectServiceTicketController` is a narrow coordinator. It reads Project Customer, Location, and Contact context through the existing organization-scoped `CustomerDirectory`, invokes canonical Ticket creation, resolves the new Ticket through `ServiceOperationsDirectory`, and links it through `ProjectWorkflow::linkTicket()`.

An outer database transaction surrounds Ticket/Visit creation, Project linking, and both audit trails. A mismatch or link persistence failure rolls back the Ticket, Visit, sequence allocation, pivot, and audit writes.

## Authorization and integrity

The Project entry point requires both:

- `projects.admin`, through `ProjectPolicy::administer`; and
- canonical Service Ticket creation authority, currently `dispatch.manage` through `ServiceTicketPolicy::create`.

The active Organization membership and explicit capability overrides remain authoritative. The Project Customer is imposed server-side; posted Customer tampering is rejected. Locations and Contacts must be active, belong to the active Organization, and belong to that Customer. A different same-Customer Location requires the existing explicit mismatch confirmation. Customerless Internal Projects cannot use the workflow.

## Validation record

Focused characterization before implementation: 31 tests, 185 assertions, passed.

Focused implementation coverage includes canonical numbering/defaults, Project context, customer tampering, Location mismatch and rollback, cross-Customer and cross-Organization rejection, dual authorization, optional Visit scheduling/assignment/lead/conflict behavior, pivot attribution, audit activity, and ordinary/manual-link regression. The final focused suite passed 38 tests with 234 assertions.

Final local validation:

- Full PHPUnit: 339 tests, 2,734 assertions, passed.
- Composer validation and security audit: passed; no advisories.
- Pint, compiled-Blade syntax for 172 files, Vite production build, and `git diff --check`: passed.
- Isolated beta exact fixtures and backup validation: passed; Projects workspace/detail remained at 16/24 queries.
- Full Playwright/axe: 21 applicable tests passed and 17 intentional device/screenshot-only cases skipped.
- Project-context create form passed at 390, 768, 1280, 1440, and 1920 px with no horizontal overflow or serious/critical axe findings.

Responsive review images are stored under [`docs/ui-review/project-service-ticket-creation`](ui-review/project-service-ticket-creation/).

## Known limitations and non-goals

- Project Tasks, Workstreams, Milestones, notes, dates, and status do not populate or transition the Ticket.
- Project files, billing ownership, Inventory, Catalog changes, APIs, and event buses remain outside this delivery.
- Completed/canceled Project link semantics are unchanged from Projects V1.
