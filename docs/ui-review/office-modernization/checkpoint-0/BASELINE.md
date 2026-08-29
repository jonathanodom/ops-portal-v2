# Office UI Modernization — Checkpoint 0 Baseline

## Source and capture environment

- Branch: `feat/office-ui-consistency-v1`
- Accepted baseline SHA: `87c94b33ab2d38dc18d9dfde973b94b9aeed3c91`
- `origin/main` at capture: `87c94b33ab2d38dc18d9dfde973b94b9aeed3c91`
- GitHub Actions: run `33235806274`, passed on the baseline SHA
- Capture role: isolated beta Super Admin
- Capture database: `database/beta.sqlite`, recreated by `composer beta:setup`
- Capture commit behavior: no Office presentation changes; screenshots represent the accepted baseline
- Viewports: P 390×844, T 768×1024, L 1280×800, D 1440×900, W 1920×1080

The retained development database and its customer data were not used. The pre-existing untracked JARVIS PDF was not read, changed, or staged.

## Route and application inventory

- Office routes: 264 total, including 90 GET-capable routes.
- Office access remains gated by active organization membership and `experience.office.access`.
- Page actions remain policy/capability gated. Checkpoint 0 used Super Admin only so every reachable Office navigation surface could be inventoried without changing role defaults.
- Organization resolution, route binding, query scopes, and business workflows were not changed.
- Current width conventions are `workspace`, `detail`, `form`, and `default`, aligned between the utility header and main content by the Office layout component.
- Existing reusable Office presentation includes page/record headers, detail navigation, Customer and Billing workspace tabs, invoice command bars, settings navigation, responsive tables/cards, badges, native dialogs, and visible focus styles.

The detailed reachability assessment is in [SCENE_INVENTORY.md](SCENE_INVENTORY.md).

## Screenshot baseline

Ninety reproducible screenshots are stored in [`before`](before). They cover:

- 24 primary Office workspaces at the 1440×900 desktop viewport.
- 11 R5 primary workspaces at all five required viewports.
- Six reachable major record details at desktop.
- Customer, Project, Service Ticket, and Closeout Review details at all five required viewports.

Representative captures:

- [Customers desktop](before/C01-customers-index-default-D-baseline.png)
- [Customers phone](before/C01-customers-index-default-P-baseline.png)
- [Project detail desktop](before/J03-project-detail-default-D-baseline.png)
- [Service Ticket detail phone](before/ST03-service-ticket-detail-default-P-baseline.png)
- [Invoices desktop](before/I01-invoices-index-default-D-baseline.png)
- [Dispatch wide](before/D01-dispatch-index-default-W-baseline.png)

The capture harness is `tests/Browser/office-ui-baseline.spec.js`. It is skipped unless both `BETA_DEMO_PASSWORD` and `OFFICE_UI_BASELINE_DIR` are supplied, so normal browser validation does not generate review artifacts.

## Data and state baseline

The isolated beta fixture validated exactly:

| Record | Count |
|---|---:|
| Organizations | 1 |
| Users / role assignments | 5 / 5 |
| Customers / locations | 250 / 400 |
| Service Tickets / Visits | 500 / 1,000 |
| Closeouts / media metadata | 200 / 500 |
| Projects / workstreams / tasks / milestones | 2 / 10 / 4 / 2 |
| Billing Handoffs / Invoices | 2 / 2 |

The beta fixture contains no Catalog Services, Products, Packages, Opportunities, Quotes, Proposal Publications, payment transactions, or customer-service enrollments. Their indexes and empty states are real and captured where reachable; their record-detail and transactional scenes are marked unavailable rather than mocked.

## Performance and query baseline

`php artisan beta:benchmark --env=beta --runs=10 --fail-on-budget` passed:

| Surface | Warm p95 | Maximum queries |
|---|---:|---:|
| Office Dashboard | 19.1 ms | 36 |
| Dispatch | 22.6 ms | 15 |
| Projects workspace | 19.7 ms | 16 |
| Customer detail | 14.8 ms | 21 |
| Project detail | 23.0 ms | 28 |
| Service Ticket detail | 19.6 ms | 27 |
| Closeout Review detail | 16.2 ms | 29 |
| Authorized media first byte | 0.1 ms | — |

These are local SQLite diagnostic values, not production latency claims. Existing query ceilings remain the regression authority.

## Baseline observations

- Primary pages use several generations of header, toolbar, filter-card, table, and card patterns.
- The desktop sidebar consumes stable width, but workspace density varies substantially by module.
- Responsive fallbacks generally avoid horizontal overflow, although long record details create very tall phone pages.
- Customers and Locations already provide the clearest shared workspace convention.
- Invoices already provide a compact command-bar pattern worth preserving.
- A collapsed desktop sidebar, global command-search overlay, saved views, column chooser, bulk actions, and generalized list/detail split panes are not established baseline behavior. They require explicit implementation scope and cannot appear only in screenshots.
- Commercial and Catalog detail modernization needs sanitized fixtures before those scenes can be visually accepted.

## Validation results

- Composer manifest validation: passed.
- Composer security audit: no advisories.
- Pint check: passed.
- Compiled Blade syntax: 210 files passed.
- Vite production build: passed (57 modules).
- Full PHPUnit regression: 469 tests, 4,140 assertions passed in 161.09 seconds.
- Beta fixture validation: passed, including SQLite integrity.
- Beta benchmark/query budgets: passed.
- Opt-in baseline screenshot test: two tests passed; 90 screenshots produced.
- Existing Playwright/axe suite: 30 applicable tests passed, 30 project/fixture-conditional tests skipped, no failures, in 3.7 minutes.
- `git diff --check`: passed.

## Checkpoint boundary

Checkpoint 0 changes only review documentation, baseline images, and the opt-in screenshot harness. No Office or Field UI, route, policy, query, schema, or workflow behavior is changed. Checkpoint 1 must not begin until the owner approves this baseline and the documented fixture gaps.
