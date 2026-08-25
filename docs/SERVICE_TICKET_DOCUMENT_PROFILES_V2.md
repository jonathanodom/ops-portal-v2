# Service Ticket Document Profiles V2

## Baseline and dependency

PR E branches from merged `main` at `38a8f46a59d3ec929a62cfa9160c706c444d24f1`. That merge includes Field Acknowledgment & Signature V1. Its post-merge GitHub Actions run `32879348237` passed core, MySQL backup/restore safety, browser/axe, and aggregate validation.

PR D has no paper-signature readiness mode. The Technician Work Order therefore provides a clearly labeled physical signature block without treating it as Portal acknowledgment evidence or changing digital Closeout readiness.

## Profiles and routes

- `/office/service-tickets/{ticket}/documents/technician-work-order`: field/site working copy with scope, schedule, crew, Work Items, known materials, field-note space, recorded acknowledgment status, and a paper signature block.
- `/office/service-tickets/{ticket}/documents/completion-summary`: concise customer who/what/when/where/outcome record using elapsed on-site windows.
- `/office/service-tickets/{ticket}/documents/customer-service-record`: fixed customer-safe service-history projection designed for future My NewDay reuse.
- `/office/service-tickets/{ticket}/documents/detailed-service-report`: internal operational snapshot with factual crew timelines, corrected effective time, Review adjustments, Work Item attribution, Closeout versions, evidence/file indexes, and capability-gated billing context.
- The legacy `/office/service-tickets/{ticket}/print` route remains a successful HTML response and renders the Technician Work Order.

Every response is authenticated, organization scoped, policy checked, `private, no-store`, `nosniff`, `noindex, nofollow`, and `no-referrer`. Profile selection is defined by four bounded routes; arbitrary template names are not accepted.

## Projection boundary

Each profile has its own projection under `App\Domain\ServiceTickets\Documents`. Technician and customer profiles load only their required relationships. `CustomerServiceRecordProjection` constructs an explicit scalar allowlist; it never builds the internal report and removes fields afterward. Super Admin privileges therefore cannot expand customer-safe output.

The document-specific signature image route is Ticket scoped and requires `service_tickets.view`. It streams the existing PR D private object without copying it or exposing its storage key. The original PR D evidence route retains its stricter `closeouts.inspect` behavior.

## Time and work semantics

- Schedule and Visit facts render in the Visit/Location IANA timezone; generated time uses the Organization timezone.
- Actual calculations use PR A effective corrected timestamps.
- Customer elapsed site time is earliest completed on-site start through latest completed on-site end. It is never inflated by multiple technicians.
- Detailed factual crew time sums each effective entry and separates En route, On site, and Other.
- Review-approved minutes remain a separate labeled value.
- PR C current attribution remains operational work-time attribution—not billing allocation—and includes any Unallocated remainder.
- Active entries say `In progress`; no end or duration is invented.
- Work Item document language is `Visit / Follow-up`, never `Provenance`.

## Acknowledgment and version ownership

Signed Closeout versions render signer, role, signed time, statement version, and the authenticated private signature image. Fallback versions render their category, known POC, and detail. Each projection reads acknowledgment from the exact Closeout version; a v1 signature is not inherited by v2.

## Capability behavior

All four profiles require active Office access and `service_tickets.view`. Detailed Closeout narrative, evidence, acknowledgment, and Review data require `closeouts.inspect`. Billing Handoff context requires `billing_handoffs.view`; Invoice/payment summary requires `invoices.view`. Detailed report availability itself does not depend on those additional capabilities.

## Print and responsive behavior

The existing browser Print / Save as PDF architecture remains. Letter portrait CSS adds non-splitting signature/acknowledgment blocks, bounded signature images, reusable Visit report breaks, wrapping fixed-layout tables, repeated table headers, responsive one-column grids, and no horizontal scrolling. No server PDF renderer, stored document snapshot, public link, customer portal, or delivery subsystem is introduced.

## Query measurements

The focused representative fixture recorded bounded request counts, including middleware and authorization:

- Technician Work Order: 24 queries (guard: 26)
- Completion Summary: 20 queries (guard: 22)
- Customer Service Record: 23 queries (guard: 25)
- Detailed Service Report: 34 queries (guard: 36)

The Detailed profile cost is fixed by eager-loaded relationship groups and does not query per Visit, Time Entry, Work Item, or Closeout row.

## Validation

- Focused profile and existing printable-document suites: 10 tests, 209 assertions.
- Combined PR A/PR B/PR C/PR D, Field execution, Closeout Review, and document regressions pass after retaining the original PR D signature-route restriction.
- Full PHPUnit: 416 tests and 3,512 assertions passed.
- Pint, Composer validation/audit, compiled Blade lint, Vite production build, and `git diff --check` passed.
- Beta validation passed its exact fixture and SQLite-integrity assertions. The 10-run benchmark remained within query ceilings; representative warm averages were Dashboard 17.3 ms/36 queries, Dispatch 23.5 ms/15, Ticket Detail 15.8 ms/27, and Review Detail 14.1 ms/29.
- Playwright exercised all four Service Ticket profiles at 390, 768, 1280, 1440, and 1920 pixels. Responsive/print-media checks and axe serious/critical checks passed. Review images are stored in `docs/ui-review/service-ticket-documents-v2`.
- GitHub Actions results are recorded in draft PR E before review.

## Future boundary

The customer-safe projection can later support My NewDay through a separately authorized customer channel. This PR adds no customer portal, public URL, server PDF, persisted snapshot, email/SMS delivery, remote e-signature, template builder, contract acceptance, billing approval, or new time semantics.
