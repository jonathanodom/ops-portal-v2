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

## Slice 2: collapsed sidebar flyouts

In collapsed icon-only mode, direct destinations remain icon links and each authorized group renders as one icon button. Activating a group opens a viewport-bounded flyout containing its server-rendered authorized children. A second activation closes it; opening another group closes the first; clicking outside, scrolling, resizing, or pressing Escape dismisses it. Escape restores focus to the originating group button.

The active group icon retains a restrained blue indication without opening automatically. Active child links keep `aria-current="page"` inside the flyout. Tooltips remain available while flyouts are closed and are suppressed for the open group to prevent overlapping floating layers.

Flyout state is intentionally ephemeral. Collapsing the sidebar closes expanded disclosures, while expanding it closes flyouts and restores Slice 1 group preferences with the active-route override.

## Deferred work

- Slice 3: grouped mobile drawer with account, sign-out, and Field switching.
