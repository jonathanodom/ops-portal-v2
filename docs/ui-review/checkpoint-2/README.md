# UI Test: Customer and Location Detail — Checkpoint 2

## Review gate

Checkpoint 2 applies the approved Office detail-page convention only to Customer Detail and Location Detail. It does not change forms, Service Tickets, Closeout Review, Invoices, Payments, field execution, routes, controllers, authorization, or data behavior. Checkpoint 3 requires separate visual approval.

## Origin and scope

- Originating merged `main` SHA: `1a2faeff1617cdc0be580e0383fa33e9030c045c`
- Origin commit: `Merge pull request #13 from jonathanodom/ui-test/desktop-workspace-refinement`
- Working branch: `ui-test/desktop-workspace-refinement`
- Database/schema changes: none
- Route, controller, policy, authorization, and query changes: none
- Customer deletion and duplicate merging remain deferred to a separate customer-data PR.

## Design decisions

- Both records use the existing `detail` width mode: responsive full width below the cap and a structured 1600px maximum on large desktops.
- A shared record header standardizes back navigation, title, status context, description, and authorized actions.
- Customer Detail keeps Overview, Locations, and Contacts visible together. Accessible in-page links provide fast navigation without dynamic panels or JavaScript.
- Customer locations and contacts use compact, scan-friendly rows rather than equal-sized decorative cards. Counts come from already loaded collections.
- The Customer overview rail contains identity, account contact information, and office notes. It stacks before operational sections on smaller screens.
- Location Detail separates field-visible information from clearly labeled office-only notes. Its context rail links the owning Customer and contains timezone, role, status, and the authorized edit action.
- Existing status badges, brand tokens, focus treatment, responsive gutters, and 44px mobile controls are retained.

## Screenshot gallery

### Customer Detail

| Viewport | Before | After |
| --- | --- | --- |
| 390 × 844 | ![Customer Detail before at 390px](before/customer-detail-390.png) | ![Customer Detail after at 390px](after/customer-detail-390.png) |
| 768 × 1024 | ![Customer Detail before at 768px](before/customer-detail-768.png) | ![Customer Detail after at 768px](after/customer-detail-768.png) |
| 1280 × 800 | ![Customer Detail before at 1280px](before/customer-detail-1280.png) | ![Customer Detail after at 1280px](after/customer-detail-1280.png) |
| 1440 × 900 | ![Customer Detail before at 1440px](before/customer-detail-1440.png) | ![Customer Detail after at 1440px](after/customer-detail-1440.png) |
| 1920 × 1080 | ![Customer Detail before at 1920px](before/customer-detail-1920.png) | ![Customer Detail after at 1920px](after/customer-detail-1920.png) |

### Location Detail

| Viewport | Before | After |
| --- | --- | --- |
| 390 × 844 | ![Location Detail before at 390px](before/location-detail-390.png) | ![Location Detail after at 390px](after/location-detail-390.png) |
| 768 × 1024 | ![Location Detail before at 768px](before/location-detail-768.png) | ![Location Detail after at 768px](after/location-detail-768.png) |
| 1280 × 800 | ![Location Detail before at 1280px](before/location-detail-1280.png) | ![Location Detail after at 1280px](after/location-detail-1280.png) |
| 1440 × 900 | ![Location Detail before at 1440px](before/location-detail-1440.png) | ![Location Detail after at 1440px](after/location-detail-1440.png) |
| 1920 × 1080 | ![Location Detail before at 1920px](before/location-detail-1920.png) | ![Location Detail after at 1920px](after/location-detail-1920.png) |

## Viewport observations

| Width | Presentation | Horizontal overflow | Detail width |
| --- | --- | --- | --- |
| 390 | Single-column stack | None | 375px browser content area |
| 768 | Single-column stack | None | 753px browser content area |
| 1280 | Main column + context rail | None | 1032px |
| 1440 | Main column + context rail | None | 1192px |
| 1920 | Main column + context rail | None | 1600px (previously 1280px) |

## Validation

Baseline on the originating SHA:

- PHPUnit: 115 tests, 877 assertions, passed in 111.05s.

Checkpoint results:

- PHPUnit: 117 tests, 910 assertions, passed in 50.79s.
- Customer/Location focused suite: 14 tests, 122 assertions, passed.
- Playwright/axe: 6 applicable tests passed; 6 opposite-project tests skipped as configured. No serious or critical axe violations.
- Beta fixture validation: exact profile passed after a guarded isolated-beta reset (1 organization, 5 users, 250 customers, 400 locations, 500 tickets, 1,000 visits, 200 closeouts, and 500 media records); SQLite integrity passed.
- Beta hardening: 8 tests, 50 assertions, passed.
- Composer validation: passed; Composer audit: no security advisories.
- Pint: passed.
- Compiled Blade syntax: 125 files passed.
- Vite production build: passed.
- Diff check: passed.

## Changed files

- `resources/views/office/customers/show.blade.php`
- `resources/views/office/locations/show.blade.php`
- `resources/views/components/office/record-header.blade.php`
- `resources/views/components/office/detail-nav.blade.php`
- `resources/css/app.css`
- `tests/Feature/CustomerLocationFoundationTest.php`
- `tests/Browser/beta-accessibility.spec.js`
- `docs/ui-review/checkpoint-2/` review notes and 20 comparison screenshots

## Known inconsistencies and deviations

- Other record details retain their current presentation until Checkpoint 3 or a later approved cleanup.
- Create/edit forms continue using their existing layout; form refinement is outside this checkpoint.
- No empty future sections or tabs were added.
- Raw comparison images remain in the draft PR for visual approval and can be consolidated before the UI branch is finally retired.
