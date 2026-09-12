# Office Collapsible Navigation V1

The full-width desktop Office sidebar groups domain destinations under accessible disclosure buttons while preserving capability-aware link visibility and active-route styling.

## Structure

- Home, Office Updates, Customers, Projects, and Settings remain direct links.
- Sales contains Leads, Opportunities, and Quote Approvals.
- Service contains Service Tickets, Dispatch, and Review.
- Catalog contains Products, Services, Packages, and Subscriptions.
- Billing contains Invoices and Billing Handoffs.
- Operations contains Health and Admin Archive.

A group is omitted when the active membership cannot access any of its children. The existing mobile navigation remains flat and unchanged.

## State and active routes

Disclosure preferences are stored in browser local storage under `ndt:office-nav-groups:{user_id}:{organization_id}`. Service is open by default for first-time users; other groups are collapsed unless they contain the active route. An active route always forces its group open, regardless of a saved collapsed preference.

In collapsed icon-only sidebar mode, group headers are hidden and authorized child links remain available as the existing icon-and-tooltip navigation. Group flyouts and a grouped mobile drawer are intentionally deferred.

## Deferred work

- Slice 2: grouped icon-only flyouts, focus handling, and tooltip integration.
- Slice 3: grouped mobile drawer with account, sign-out, and Field switching.
