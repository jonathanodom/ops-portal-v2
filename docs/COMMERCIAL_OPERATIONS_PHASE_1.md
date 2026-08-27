# Commercial Operations V1 — Phase 1 Opportunity Foundation

## Scope and gate

Phase 1 adds the organization-scoped Opportunity workspace only. Quote construction, Commercial documents/revisions, Proposal publication, public links, acceptance, Project conversion, Change Orders, and deposit Invoices remain absent.

Branch: `feat/commercial-operations-v1-checkpoints-0-3`
Phase 0 accepted head: `440ac2959f97e48c614d5ef4793d647aec8178e5`

## Additive schema

- `organization_commercial_settings`: typed future Proposal policy defaults; no unbounded JSON.
- `opportunity_stages`: one organization pipeline with stable semantic kinds, editable names/colors/order/probability.
- `opportunities`: immutable organization/year number, canonical Customer, optional site/contact, owner, stage, estimate, probability override, qualification metadata, next action, and closure metadata.
- `opportunity_tasks`: assigned follow-ups with due/completion state.
- `opportunity_activities`: staff notes, call records, and email summaries.
- `opportunity_attachments`: private opaque files with soft removal and after-commit cleanup.
- `commercial_user_preferences`: organization/user Kanban or List preference.

The two reversible migrations are additive. Existing Customer, Project, Service Ticket, Visit, Closeout, Catalog, Billing Handoff, Invoice, Payment, and private-evidence tables are unchanged.

## Authorization

| Capability | Super Admin | Dispatcher | Other seeded roles |
| --- | --- | --- | --- |
| `opportunities.view` | Yes | Yes | No |
| `opportunities.manage` | Yes | Yes | No |
| `opportunities.admin` | Yes | No | No |

Explicit membership grants and denials remain authoritative. Inactive memberships cannot enter Office. All records, nested records, files, stage selection, Customer/site/contact selection, and assignees are resolved within the active Organization.

## Lifecycle and audit

- Default stages: New, Qualifying, Quoting, Presented, Won, Lost.
- Ordinary Opportunity managers may move among open stages and Lost.
- Presented and Won require an explicit `opportunities.admin` confirmation and safe override audit.
- Lost reason/note are optional; Lost may reopen and clears its closure metadata.
- Won is final.
- Activities store their body in the dedicated activity table; audit metadata contains only IDs/types/state/field names.
- Attachment storage keys are opaque and never enter audit metadata.
- Opportunity numbers use `document_sequences` as `OPP-YYYY-NNNN`, organization/year scoped and row locked.

## Workspace

- `/office/opportunities` uses the Office `workspace` width and defaults to Kanban.
- List view is responsive and the last selected view persists per user and Organization.
- Search and stage/owner/priority filters apply to both modes.
- Cards show Customer/site, estimated value, the honest `Not started` Quote/Proposal placeholder, and latest bounded activity.
- Detail uses the Office `detail` width with Overview, Tasks, Files, and Activity sections.
- Commercial settings are available as an authorized Settings tab.
- No nonfunctional Quote button or customer-facing Commercial route is rendered.

## Retained local database

Before migration:

- Verified backup: `storage/app/backups/commercial-phase0-before-phase1-20260827.sqlite`
- Backup SHA-256: `F1B9DF9AAEB98B5CAA035A773287ED9744AF009DBB4CCDE40297DB897B267A11`
- Isolated restore: integrity, migrations, table counts, representative relationships, and workflows passed.
- 48 migrations, 75 application tables, 750 private objects / 19,359,009 bytes.

After migration:

- 50 migrations and 82 application tables.
- One settings row and six default stages for the retained Organization.
- Zero seeded/fabricated Opportunities, tasks, activities, or attachments.
- Existing Customers 8, Projects 2, Service Tickets 12, Visits 13, Invoices 13, Payments 3, and Audit Events 177 remained unchanged.
- Private storage remained 750 objects / 19,359,009 bytes.
- Ending SQLite SHA-256: `34B857C65FE55C2127EF4521DC4C6A667FF6AC3159C09EF7D9D69E66A126C29E`.

`composer phase:update` reached dependency installation but its optimized-autoload subprocess stalled before migration. It was safely interrupted before any schema write. The repository-equivalent additive `artisan migrate --force` and idempotent `artisan db:seed --force` steps then completed successfully; frontend and full quality commands are validated separately below.

## Validation

- Phase 1 focused: 9 tests / 80 assertions passed.
- Expanded Commercial/access/settings/Projects slice: 32 tests / 233 assertions passed.
- Full PHPUnit: 441 tests / 3,842 assertions passed in 172.889 seconds; 126 MB peak.
- `composer check`: passed Composer validation, Pint, 199 compiled-Blade syntax checks, 441 tests / 3,842 assertions in 101.47 seconds, and the Vite production build.
- GitHub MySQL/browser/safety results are recorded in the draft PR before the owner gate closes.

## Safe local test data

Use the existing active Super Admin or Dispatcher and existing active Customers. Do not run a reset or add production/beta imports.

1. Open **Opportunities → New opportunity**.
2. Create one customer-wide Opportunity and one tied to an existing active Service Location/contact.
3. Use `.test` contact information and non-sensitive qualification summaries if new Customer data is needed.
4. Add one open follow-up, one Note, one Call, one Email, and a harmless synthetic PDF/image.
5. Remove only the synthetic Opportunity file after checking authorized delivery.
6. Do not enter real secrets, payment credentials, or customer-sensitive content during UI acceptance.

## Owner UI acceptance checklist

- Navigation is shown only with `opportunities.view` and first load is Kanban.
- Kanban and List selection persists after leaving and returning.
- Search/stage/owner/priority filters retain correct Customer and Organization boundaries.
- Create/edit/detail correctly enforce Customer, optional site/contact, owner, estimate, probability, and next action.
- Cards contain Customer/site, estimate, `Not started`, and latest activity.
- Tasks complete/reopen/cancel and remain assigned only to active Organization members.
- Notes/calls/emails and safe lifecycle/file events appear in Activity.
- Private files open only for authorized Organization members and remove cleanly.
- Dispatcher cannot access Commercial Settings or force Presented/Won.
- Super Admin can explicitly override Presented/Won; Won cannot reopen; Lost can reopen.
- At 390, 768, 1280, 1440, and 1920px: no page overflow, controls retain visible focus, mobile actions are at least 44px, Kanban scroll remains contained, and List uses cards below desktop.
- Existing Field, Projects, Tickets, Catalog, Review, Billing, Invoice, and Payment pages remain unchanged.

**WAITING FOR OWNER UI ACCEPTANCE — PHASE 1**
