# Office UI Modernization — Checkpoint 1

## Scope

Checkpoint 1 establishes shared Office presentation primitives and proves them on Customers, Opportunities, and Billing / Invoices. It does not change controllers, routes, authorization, queries, persistence, or workflow behavior.

## Implemented foundation

- Compact primary toolbar with title/context, search, view selection, Filters disclosure, active-filter chips, secondary actions, and one primary action.
- Accessible GET-based filter panels and removable active-filter chips.
- Shared view switcher, alert, and loading/empty/error/permission/confirmation state components.
- Flat spacing, typography, focus, border, and responsive rules built on the existing NewDay brand tokens.
- 44px mobile controls and progressive wrapping without page-level horizontal overflow.
- Invoice workspace uses a compact View menu through ordinary desktop widths and exposes the complete status tab row at wide desktop width.

## Proof workspaces

| Workspace | Preserved behavior | Foundation proof |
| --- | --- | --- |
| Customers | Existing search, status/type filtering, Customer/Location navigation, pagination, and permissions | Toolbar, filter disclosure, chips, actions, responsive results, empty state |
| Opportunities | Existing Kanban/List preference, search, filters, sorting, and capability-gated creation | Toolbar, view switcher, filter disclosure, chips, alerts, empty state |
| Billing / Invoices | Existing ready-handoff and invoice workspaces, search/filter/sort state, invoice creation, and settings access | Toolbar, responsive view menu/tabs, filter disclosure, chips, empty state |

## Responsive observations

- 390px: one-column toolbar, full-width primary action, 44px controls, card results, no horizontal page overflow.
- 768px: progressive two-column toolbar layout with card results.
- 1280px and 1440px: one-row compact toolbar and dense desktop tables; invoice status uses the compact View menu.
- 1920px: full-width workspace tables and the complete invoice status tab row.

Screenshots are stored in `docs/ui-review/office-modernization/checkpoint-1/after` for all three proof workspaces at 390, 768, 1280, 1440, and 1920 pixels. Two additional Customer filter-state screenshots show the open filter panel and active chips.

## Validation

- Focused feature regression: Customer Location Foundation, Commercial Operations Phase 1, and Phase 6 Invoicing passed (73 tests; Phase 6 reported 49 tests / 525 assertions).
- Full PHPUnit: 469 tests / 4,140 assertions passed in 181.55 seconds.
- Composer validation: passed.
- Composer audit: no security advisories.
- Pint: passed.
- Compiled Blade syntax: 216 files passed.
- Vite production build: passed.
- Beta fixture: guarded reset completed; exact fixture validation passed; Beta Hardening passed 8 tests / 56 assertions.
- Beta benchmark: all route/query budgets passed; highest recorded p95 was Project Detail at 23.8 ms with 28 queries.
- Focused toolbar Playwright/axe: 2 tests passed across the five required viewports.
- Full Playwright/axe: 32 passed / 32 intentionally skipped in 3.1 minutes after restoring the stateful beta fixture.
- `git diff --check`: passed.

## Known limitations

- The shared primitives are intentionally proven on only three representative workspaces. Applying them across the remaining Office routes belongs to Checkpoint 2.
- No loading state is forced onto synchronous server-rendered pages; the shared component is available for workflows that have a real loading boundary.
- The screenshot fixture has few Opportunities, so its empty-state proof is intentional.

## Owner review

Review Customers, Opportunities, and Billing / Invoices at desktop and phone widths. Check toolbar density, Filters behavior, removable chips, invoice View menu/status tabs, keyboard focus, result readability, and absence of horizontal scrolling. Checkpoint 2 remains blocked until explicit approval.
