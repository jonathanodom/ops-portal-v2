# Office UI Modernization — Checkpoint 3

## Scope

Checkpoint 3 aligns primary Office detail, create, and edit workflows without changing routes, validation, authorization, persistence, calculations, or lifecycle behavior.

## Updated workflows

- Shared compact record and form headers with consistent back context, badges, and action placement.
- Flat grouped forms with reduced spacing and solid sticky save/cancel bars.
- Customer creation and editing, Contacts, and Service Locations.
- Project, Opportunity, Service Ticket, Visit scheduling, and direct Invoice creation.
- Catalog Service, Product, Package, Category, and Unit creation/editing.
- Service Ticket, Opportunity, and Closeout Review contextual headers.
- Quick Customer, Office execution, and administrative closeout dialogs use one full-device dialog frame.
- Existing Invoice command bar, item editor, billing drawer, financial/destructive permissions, and workflow rules remain unchanged.

## Responsive review

- Phone forms stack controls and actions without horizontal overflow; primary save actions remain at least 44px high.
- Desktop forms retain the readable `form` width while using denser sections and aligned actions.
- Ticket and Review details retain their contextual side rails and collapse cleanly at narrow widths.
- Review screenshots are stored in `docs/ui-review/office-modernization/checkpoint-3/after`.

## Validation

- Affected Customer, Project, Opportunity, Service Ticket, Invoice, and Review regression: 98 tests / 867 assertions passed.
- Catalog regression: 22 tests / 215 assertions passed.
- Focused Checkpoint 3 Playwright/axe: passed at 390px and 1440px across eight create workflows plus Service Ticket and Review details.
- Full PHPUnit: 469 tests / 4,140 assertions passed.
- Full Playwright/axe: 34 passed / 34 intentionally skipped across desktop and mobile projects.
- Beta fixture validation: exact expected counts and SQLite integrity passed; beta hardening added 8 tests / 56 assertions.
- Beta benchmark: office dashboard 19.9 ms p95, Today 13.1 ms, Dispatch 25.6 ms, Projects 16.9 ms, Customer detail 19.8 ms, Project detail 28.5 ms, Ticket detail 23.0 ms, Review detail 21.8 ms, and media first byte 0.1 ms.
- Composer validation/audit, Pint, compiled-Blade lint, Vite production build, and `git diff --check`: passed.

## Known limitations

- Inline editing embedded inside large record details remains workflow-specific; Checkpoint 3 aligns its surrounding surface but does not convert it into a new interaction model.
- The beta fixture does not contain an Opportunity detail record, so Opportunity detail is covered by feature regression while its create workflow is included in browser/axe review.
- Field execution is unchanged except where Office-only dialogs share the standardized frame.

## Owner review

Review the Customer, Project, Opportunity, Service Ticket, Invoice, and Catalog create screens at phone and desktop widths. Confirm the compact headers, grouped fields, persistent save/cancel actions, dialog sizing, contextual Ticket/Review headers, keyboard focus, and absence of horizontal overflow. Do not begin Checkpoint 4 until explicit approval.
