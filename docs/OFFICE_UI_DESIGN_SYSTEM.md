# Office UI Design System

This file records the Office presentation conventions implemented and approved through UI Test Checkpoints 1–3. It is a practical contract for future modules, not a theme catalog.

## Width modes

Select the width deliberately on `<x-layouts.office>`:

- `workspace`: queue and directory pages that benefit from nearly all available desktop width.
- `detail`: structured record pages capped near 1600px, normally with a main column and contextual rail.
- `form`: create/edit flows capped near 896px for readable input lengths.
- `default`: legacy/general pages using the existing `max-w-7xl` behavior.

The utility header and main content must use the same width mode so their gutters align.

## Workspace pages

Use the shared conventions demonstrated by Customers, Locations, Service Tickets, Closeout Review, and Billing:

1. `<x-office.page-header>` provides the eyebrow, one page-level `h1`, concise description, and authorized primary actions.
2. Route-backed workspace tabs are appropriate only for closely related record types, as with Customers and Locations. Do not add empty or client-only tabs.
3. `.office-filter-toolbar` contains GET filters with persistent query values and explicit Filter/Clear actions.
4. `.office-table-wrap` and `.office-data-table` provide the desktop scan view at `lg` and above.
5. `.office-mobile-list` and `.office-mobile-card` provide the same records below `lg`; do not squeeze a desktop table into a phone viewport.
6. Empty states explain what is absent and offer a relevant next step without fabricated counts or metrics.

Queue columns must come from data already loaded for the page. Presentation changes must not introduce hidden N+1 queries or invent operational meaning.

## Detail pages

Use the shared conventions demonstrated by Customer, Location, and Invoice detail:

- `<x-office.record-header>` provides a 44px back link, record title, badges, description, and contextual actions.
- `<x-office.detail-nav>` provides ordinary anchor links to sections that remain fully rendered without JavaScript.
- `.office-detail-grid` stacks by default and becomes a main/rail layout at `xl`.
- `.office-detail-main` holds primary record content; `.office-detail-rail` holds contextual facts and actions.
- Sections use clear borders, compact headers, stable IDs, and `scroll-mt-6` for anchored navigation.

The rail is contextual, not a dumping ground. High-frequency work stays in the main column; identity, totals, status, and bounded actions belong in the rail.

## Forms and actions

- Keep create/edit pages in `form` width unless the workflow genuinely requires a split workspace.
- Use existing `.form-label`, `.form-input`, and button classes.
- Minimum interactive height is 44px on phone layouts.
- Primary blue identifies the main action, links, focus, and selected navigation.
- Orange is reserved for priority, active work, and next-action cues.
- Destructive actions remain visually and spatially separate.
- Hidden controls never replace authorization; Blade conditions reflect policies and capabilities already enforced server-side.

## Tables and cards

- Table headers are short, operational, and use proper `scope="col"` semantics.
- The record identifier or title is the primary row link; an explicit final Open/Review action improves scanability.
- Mobile cards preserve the same essential identity, status, and next action in a compact hierarchy.
- Status badges reuse existing semantic classes rather than introducing per-page colors.
- Hover and focus-within states identify the active row without animation or shadow-heavy decoration.

## Accessibility and responsive review

Every changed Office workspace is reviewed at 390, 768, 1280, 1440, and 1920px for:

- no horizontal page overflow;
- cards below `lg` and tables at `lg` and above where applicable;
- one logical page heading and correct section hierarchy;
- visible keyboard focus and meaningful link/action text;
- 44px phone controls;
- no serious or critical axe violations;
- permission-correct actions and projections.

Field execution is a separate phone-first system. Shared brand and accessibility rules may be reused, but Office workspace changes must not casually alter Field workflows.

## Boundaries

These conventions govern presentation. They do not authorize changes to domain models, migrations, routes, policies, FSM transitions, invoice calculations, payment handling, or data retention. Backend changes require their own feature plan and tests.
