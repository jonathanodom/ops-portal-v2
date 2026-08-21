# Customer Project History

## Baseline and purpose

Customer Project History branches from `main` at `b0bc5a29a70d846e4e1180c69159676ce5e6b278`. The full GitHub Actions run for that baseline was green.

The Office Customer detail page now includes a read-only Projects section between Service Ticket and Invoice history. It provides customer context and links to the authoritative Projects workspace; it does not duplicate or mutate Project data.

## Capability behavior

- `projects.view` controls the Projects in-page navigation item, section, Project query, and rows.
- `projects.manage` controls the **New Project** action.
- The action uses the existing Project creation route with `customer_id` preselected.
- Existing Invoice and recurring Customer Service capability checks remain independent.

Explicit capability denials and inactive-membership rules remain authoritative.

## Task counts and ordering

Project rows use database `withCount()` aggregates rather than loading Tasks:

- **Open:** status is not `done` or `canceled`.
- **Overdue:** open and `due_on` is before the Organization-local current date.
- **Blocked:** status is `blocked`.

Projects are ordered by operational status—`active`, `planning`, `on_hold`, `completed`, then `canceled`—followed by most recently updated and newest identifier.

## Projection and tenant isolation

Rows include Project number, name, type, status, owner, Service Location, target date, task counts, and the canonical Project detail link. Missing owner and Location relationships degrade to **Unassigned** and **Customer-wide / multi-site**.

The query starts from the canonical Customer `projects()` relationship and additionally constrains `organization_id` to the active Organization. Tests cover another Customer, a mismatched Organization record, and an explicit `projects.view` denial that skips the Project query.

## Validation

Focused coverage verifies presentation, local-date counts, terminal Task exclusions, ordering, tenant isolation, links, capability behavior, and preservation of Service Ticket, Invoice, and recurring Customer Service history. Responsive Playwright/axe coverage exercises the Project section and navigation at 390, 768, 1280, 1440, and 1920 pixels.

Local validation completed with 385 PHPUnit tests and 3,175 assertions. The clean beta fixture passed its exact-count and integrity validation; Customer detail measured p95 `15.2 ms` with `21` queries across 10 warm runs. A fresh local and CI fixture both measured the existing NewDay Home at 34 queries, so its recently added query guard was corrected from 32 to a still-tight 35 without changing Home behavior. Composer validation/audit, Pint, compiled Blade lint, the production Vite build, focused responsive Playwright/axe coverage, and `git diff --check` passed. Final MySQL and full browser results are recorded by GitHub Actions on the pull request.

## Non-goals

This change adds no schema, capability, Project workflow, inline editing, profitability, labor rollup, Staff Time Tracking, API, or duplicate Project data.
