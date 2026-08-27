# Invoice Print Composer V1

## Baseline and scope

- Branch: `feat/invoice-print-composer-v1`
- Baseline: `b239677d33f008d9ed909768c5840a00a9c1e264`
- Baseline CI: GitHub Actions run `33030435484` passed on `main`.
- Baseline regression: 428 tests and 3,687 assertions passed.
- Delivery is presentation-only. There is no migration, saved print profile, payment change, Invoice mutation, or PDF-engine replacement.

## Browser presentation and print separation

The authenticated interactive Invoice remains at `GET /invoices/{invoice}/present`. It retains Invoice acknowledgment, electronic checkout, payment status, Service Details disclosure, PDF download, and return navigation.

The Print Invoice action now opens `GET /invoices/{invoice}/print` (`invoices.print`). The route uses the existing `invoices.present` capability, active-Organization resolution, organization-scoped Invoice lookup, issued-status guard, and safe cross-Organization denial. Responses are private, `no-store`, non-indexable, and use a restrictive referrer policy.

The print DOM is purpose-built and static. It does not load or render acknowledgment mutation forms, payment-provider configuration, payment attempts, checkout links or QR controls, connectivity messaging, interactive `details`, or application navigation inside the document.

## Composer defaults

Financial content is always included and cannot be hidden.

Ticket Invoice defaults:

- Customer Note: included when present.
- Service Details: included when an immutable snapshot exists.
- Invoice Acknowledgment: excluded.
- Customer Note page break: off.
- Service Details page break: on.
- Invoice Acknowledgment page break: off.

Direct Invoice defaults:

- Customer Note: included when present.
- Service Details: unavailable.
- Invoice Acknowledgment: excluded.

The HTML defaults are correct without JavaScript. JavaScript only applies transient show/hide and bounded break-before choices, restores product defaults, and invokes browser print. No setting is persisted.

## Customer-safe data boundaries

Ticket Service Details render only from the immutable `InvoiceServiceSnapshot` captured at issue. The composer does not re-query live detailed Service Ticket state. A legacy issued Ticket Invoice without a snapshot retains minimal Ticket identity and never fabricates current Service Details. Direct Invoices have no Service Details.

Invoice Acknowledgment is distinct from the Service Closeout acknowledgment. A recorded Invoice Acknowledgment may be enabled as static name/time evidence and is off by default. Service Closeout acknowledgment remains part of the immutable Service Details snapshot independently.

`customer_note` may print; `internal_note`, storage keys, raw snapshot JSON, payment-provider internals, and private evidence paths never print.

## Canonical PDF

The queued, private, immutable issued-Invoice PDF remains the canonical stored document. Ticket PDFs now start Service Details on a new page. Direct PDFs remain unchanged. Canonical PDFs do not include Invoice Acknowledgment, while snapshotted Service Closeout acknowledgment remains available in Service Details.

Composer settings never update, regenerate, or otherwise affect the canonical stored PDF.

## Accessibility, responsive behavior, and print

- Controls are native checkboxes and buttons with visible labels, keyboard focus, and 44px minimum targets.
- Composer behavior was exercised at 390x844, 768x1024, 1280x900, 1440x900, and 1920x1080.
- Long descriptions and Work Item titles wrap; numeric line columns remain readable without horizontal overflow.
- Screen-only controls disappear under print media.
- Letter portrait output uses bounded major-section page breaks and avoids breaking compact line/Visit cards where practical.
- The rendered validation document was two Letter pages: financial core and Customer Note on page 1, Service Details beginning on page 2.
- The focused axe run reported no serious or critical findings.

## Performance

Measured on the focused issued Ticket Invoice fixture:

- Interactive presentation: 20 queries.
- Print Composer: 16 queries.

The composer stays below the 20-query regression ceiling and does not load checkout/provider dependencies.

## Validation

- Focused Invoice Service Context: 10 tests, 134 assertions, passed.
- Full PHPUnit: 432 tests, 3,739 assertions, passed in 5m30s with 124 MB peak memory.
- Beta Hardening fixture: 8 tests, 56 assertions, passed.
- Isolated beta fixture validation: exact counts and SQLite integrity passed.
- Beta benchmark: all budgets passed; Dashboard p95 47.6 ms, Dispatch p95 61.2 ms, Ticket detail p95 18.3 ms, Review detail p95 22.8 ms.
- Focused Print Composer Playwright/axe: passed at all required widths and print media.
- Full CI-equivalent Playwright/axe: 29 passed and 25 intentionally skipped in 8.2 minutes; no serious or critical axe findings.
- Clean-fixture Field workflow retry: passed; an earlier repeated local run had retained a Visit transition from the preceding timed-out browser attempt before the final clean run.
- Pint, compiled-Blade syntax, Vite production build, and `git diff --check`: passed.
- Composer strict validation and security audit: passed with no advisories.

GitHub PR checks are recorded in the draft PR validation summary.

## Non-goals

V1 does not add template builders, drag-and-drop, arbitrary line/Visit breaks, stored print preferences, Organization defaults, browser-PDF persistence, printed checkout QR codes, new acknowledgment workflows, paper signatures, customer-portal changes, per-Work-Item Billing, Invoice-line redesign, or snapshot redesign.
