# UI Test: Desktop Workspace Refinement — Checkpoint 1

## Review gate

This checkpoint is presentation-only and stops at the Customer/Location workspace. It does not redesign record details, Service Tickets, Closeouts, Invoices, Payments, or field execution. Checkpoints 2 and 3 require separate visual approval.

## Origin and scope

- Originating `main` SHA: `6ef8f5516de9312ea27ef1cc1fc4a50b510a1ef1`
- Origin commit: `Merge pull request #12 from jonathanodom/codex/ux-ticket-customer-update`
- Working branch: `ui-test/desktop-workspace-refinement`
- Database/schema changes: none
- Route, policy, permission, and controller changes: none
- Existing directory queries, eager loading, filters, pagination, and query strings are retained.

## Design decisions

- The Office layout now exposes `default`, `workspace`, `detail`, and `form` width modes. Utility-header and main-content edges share the selected mode.
- Customer and Location indexes use the uncapped `workspace` mode. At 1920px the main workspace grows from 1280px to 1657px, using the post-sidebar area and established 32px gutters.
- Customers remains the single primary-navigation entry for both customer and location routes. `Customers | Locations` provides the secondary workspace navigation.
- Index data uses dense tables from 1024px upward and compact linked cards below 1024px.
- Shared flat conventions cover page headers, tabs, filter toolbars, data tables, mobile cards, focus/hover states, and empty states. Existing brand tokens and status badges remain unchanged.
- The Office mobile primary navigation remains horizontally scrollable but hides the decorative scrollbar; keyboard and touch scrolling remain available.

## Screenshot gallery

### Customers

| Viewport | Before | After |
| --- | --- | --- |
| 390 × 844 | ![Customers before at 390px](before/customers-390.png) | ![Customers after at 390px](after/customers-390.png) |
| 768 × 1024 | ![Customers before at 768px](before/customers-768.png) | ![Customers after at 768px](after/customers-768.png) |
| 1280 × 800 | ![Customers before at 1280px](before/customers-1280.png) | ![Customers after at 1280px](after/customers-1280.png) |
| 1440 × 900 | ![Customers before at 1440px](before/customers-1440.png) | ![Customers after at 1440px](after/customers-1440.png) |
| 1920 × 1080 | ![Customers before at 1920px](before/customers-1920.png) | ![Customers after at 1920px](after/customers-1920.png) |

### Locations

| Viewport | Before | After |
| --- | --- | --- |
| 390 × 844 | ![Locations before at 390px](before/locations-390.png) | ![Locations after at 390px](after/locations-390.png) |
| 768 × 1024 | ![Locations before at 768px](before/locations-768.png) | ![Locations after at 768px](after/locations-768.png) |
| 1280 × 800 | ![Locations before at 1280px](before/locations-1280.png) | ![Locations after at 1280px](after/locations-1280.png) |
| 1440 × 900 | ![Locations before at 1440px](before/locations-1440.png) | ![Locations after at 1440px](after/locations-1440.png) |
| 1920 × 1080 | ![Locations before at 1920px](before/locations-1920.png) | ![Locations after at 1920px](after/locations-1920.png) |

## Viewport observations

| Width | Presentation | Horizontal overflow | Main workspace width |
| --- | --- | --- | --- |
| 390 | Compact cards | None | 375px browser content area |
| 768 | Compact cards | None | 753px browser content area |
| 1280 | Dense table | None | 1017px |
| 1440 | Dense table | None | 1177px |
| 1920 | Dense table | None | 1657px (previously 1280px) |

## Validation

Baseline on the originating SHA:

- PHPUnit: 113 tests, 857 assertions, passed in 21.91s.

Checkpoint results:

- PHPUnit: 115 tests, 877 assertions, passed in 75.02s.
- Customer/Location focused suite: 12 tests, 89 assertions, passed.
- Playwright/axe: 5 applicable tests passed; 5 opposite-project tests skipped as configured. No serious or critical axe violations.
- Beta fixture validation: exact profile passed (1 organization, 5 users, 250 customers, 400 locations, 500 tickets, 1,000 visits, 200 closeouts, and 500 media records); SQLite integrity passed.
- Beta hardening: 8 tests, 50 assertions, passed.
- Composer validation: passed; Composer audit: no security advisories.
- Pint: passed.
- Compiled Blade syntax: 123 files passed.
- Vite production build: passed.
- Diff check: passed.

## Changed files

- `resources/views/components/layouts/office.blade.php`
- `resources/views/components/office/page-header.blade.php`
- `resources/views/components/office/customer-workspace-tabs.blade.php`
- `resources/views/office/customers/index.blade.php`
- `resources/views/office/locations/index.blade.php`
- `resources/css/app.css`
- `tests/Feature/CustomerLocationFoundationTest.php`
- `tests/Browser/beta-accessibility.spec.js`
- `docs/ui-review/checkpoint-1/` review notes and 20 comparison screenshots

## Known inconsistencies and deviations

- Customer and Location detail pages retain the existing `default` width and presentation by design.
- Other Office indexes retain their existing list/table conventions until separately approved checkpoints.
- No counts appear in workspace tabs, avoiding additional queries.
- Raw comparison images are retained in this draft PR for visual approval and can be consolidated before merge.
