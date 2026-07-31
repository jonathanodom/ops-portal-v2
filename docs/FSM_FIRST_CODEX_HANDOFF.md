# NewDay Tech Ops Portal — FSM-First Rebuild
## Codex Build Plan and Implementation Handoff

**Document status:** Build handoff v1.0
**Owner:** Jonathan Odom / NewDay Tech
**Reference system:** Existing Ops Portal beta (preserve as read-only reference; do not modify or delete)
**New repository:** The newly created GitHub repository selected by the owner
**Primary product decision:** Build Field Service Management first; add office modules only after the field workflow is proven.

---

## 1. Mission

Build a new, intentionally narrow Ops Portal foundation for a field-service company. The first release must make the workday obvious for a technician on a phone and equally obvious for an office user reviewing the same work.

The first release is not a proposal generator, CRM suite, project-management suite, or finance system. It is a dependable FSM workflow with a small customer foundation:

```text
Customer → Service Location → Job → Visit → Field Execution → Closeout → Office Review → Billing Handoff
```

The existing beta portal is a reference for validated domain ideas and edge cases. Do not copy its broad navigation or reproduce every module. Reuse concepts only after they fit this narrower workflow.

### First-release outcome

A realistic job can be completed end to end:

1. An office user creates or selects a customer and service location.
2. The office creates a job and schedules a visit.
3. A dispatcher assigns a technician.
4. The technician opens the mobile field experience, views the job, starts travel, arrives, records work, captures evidence, and submits a closeout.
5. An office reviewer sees the same closeout packet, approves it or returns it with a reason.
6. Approval produces a billing-ready handoff, not an improvised or duplicated invoice.

If this flow is not fast, clear, and auditable, no additional module should be started.

---

## 2. Product and architecture decisions

These decisions are binding unless the owner explicitly changes them.

### 2.1 Two experiences, one system

Use the subdomain/host to distinguish presentation and workflow context, not to create separate applications or separate data stores:

- **Field host:** mobile-first, touch-friendly, task-oriented. Focus on Today, Jobs, Visits, navigation, execution, and closeout.
- **Office host:** desktop-first, queue-oriented. Focus on Customers, Jobs, Dispatch, Review, and billing handoff.

Both hosts use the same authentication, database, records, lifecycle services, audit events, and authorization policies. A user may enter either experience when authorized.

### 2.2 No profile-based lockout of the field experience

A Super Admin must be able to view and test the field experience even when they do not have a TechnicianProfile. Do not use “has technician profile” as the gate for entering the field host.

Separate these concepts:

- **Experience access:** may the user open the field or office host?
- **Capability:** may the user perform a specific action?
- **Assignment:** is the user assigned to this visit?

Examples:

- Super Admin: can enter both hosts; can inspect any visit; can perform test actions only if the explicit capability allows it.
- Dispatcher: can schedule and assign; cannot approve billing unless granted.
- Technician: can execute visits assigned to them; cannot approve their own closeout.
- Reviewer: can approve/return closeouts; cannot impersonate a technician without an explicit, audited test mode.

Use explicit policies/capabilities such as `view_field_experience`, `view_visit`, `perform_visit`, `submit_closeout`, `dispatch_visit`, and `approve_closeout`. Do not scatter role-name checks through controllers and templates.

### 2.3 Mobile-first is a product constraint

The field workflow must work at phone width first. Use large tap targets, minimal typing, persistent status/action affordances, clear offline/unavailable states, compressed payloads, and photo upload progress. Desktop responsiveness is required but is not the design starting point.

Offline sync is **not** a first-release requirement. The app must fail gracefully when connectivity is poor and never claim a write succeeded when it did not.

### 2.4 One lifecycle, separate state dimensions

Do not overload one status field with scheduling, operational, approval, and financial states. At minimum, maintain separate state dimensions:

- **Job:** open, on_hold, completed, canceled.
- **Visit:** planned, scheduled, assigned, en_route, on_site, pending_closeout, returned_for_correction, approved, canceled, customer_unavailable.
- **Closeout:** draft, submitted, returned, approved.
- **Billing handoff:** not_ready, ready, handed_off.

A return trip keeps the Job open and creates another Visit under the same Job. It must not create a disconnected duplicate job.

---

## 3. Scope lock

### In scope for the new foundation

- Authentication and organization membership.
- Host-aware field and office shells.
- Customers/accounts and contacts.
- Service locations with access notes, timezone, and primary contact.
- Jobs/service requests.
- Visits, scheduling, assignment, and status transitions.
- Technician mobile execution.
- Work notes, structured outcome, time entries, and private photos.
- Customer acknowledgment or required unavailable reason.
- Closeout submission, office review, correction/resubmission, and immutable history.
- Basic parts-used proposals without direct inventory mutation.
- Billing-ready handoff event/record (no full finance system).
- Audit trail, notifications/events, authorization, and focused tests.

### Explicitly deferred

Do not implement these in the first foundation build:

- Proposal generation, quoting, document signing, or sales pipeline.
- General CRM beyond the customer/location records needed by FSM.
- General project management, milestones, dependencies, or change orders.
- Recurring service contracts, renewals, installed-asset registry, warranties, or RMA.
- Full invoicing, payment processing, accounting ledger, reconciliation, AP, or financial reporting.
- Purchasing, inventory accounting, truck stock, or automatic stock consumption.
- AI-generated customer/technician content or autonomous messaging.
- Offline-first sync, native mobile apps, map optimization, route optimization, or video.
- Executive analytics and broad automation.

Create a visible backlog for deferred ideas. Do not add them to the navigation or schema “just in case.”

---

## 4. Canonical data model

Start with a small, explicit model. Avoid recreating dozens of loosely connected feature models.

### Required records

#### Organization
- `id`, name, timezone, active status.

#### User
- `id`, organization membership, name, email, authentication state, capabilities.
- A technician profile is optional. It must not determine access to the field host.

#### Customer
- Legal/display name, phone, email, status, notes, created/updated metadata.
- Use one canonical customer record across both hosts.

#### Contact
- Customer relationship, name, role, phone, email, preferred contact flag.

#### ServiceLocation
- Customer, address, timezone, access instructions, site notes, primary contact, active status.
- Every visit must resolve to one service location.

#### Job
- Customer, primary service location, title, description, priority, source, operational status, customer-visible summary, created-by, timestamps.
- A Job is the durable customer request/outcome. It may contain multiple Visits.

#### Visit
- Job, service location, scheduled window, assigned users, visit status, dispatch metadata, en-route/on-site timestamps, cancellation/customer-unavailable data.
- A Visit is one dispatch, not the whole customer problem.

#### VisitTimeEntry
- Visit, user, category (`travel`, `on_site`, `other`), start/end or duration, note, source, approval state.

#### VisitMedia
- Visit, uploader, private storage key, category (`before`, `after`, `as_built`, `damage`, `serial_model`, `other`), caption, upload state, timestamps.
- Media must remain private and authorization-checked.

#### VisitPartProposal
- Visit, product/reference description, quantity, serial/MAC when applicable, billing treatment, technician note, proposed-by, review state.
- This is a proposal/evidence record only in the foundation. It must not mutate inventory or invoice data.

#### Closeout
- Visit, version number, diagnosis, work performed, exceptions, recommendations, outcome (`resolved`, `needs_return_trip`, `customer_unavailable`, `on_hold`), acknowledgment/signature metadata or unavailable reason, submitted-by, approved-by, status, immutable timestamps.

#### CloseoutReview
- Closeout, reviewer, decision (`approved`, `returned`), reason, adjustments, timestamps.

#### BillingHandoff
- Job/Visit/approved closeout, status, source closeout version, created-by, handed-off-at, idempotency key.
- It is a stable boundary for a future finance module, not a substitute for an invoice.

#### AuditEvent
- Organization, actor, event type, subject type/id, safe metadata, occurred-at. Never store secrets or unnecessary private content.

### Relationships and invariants

- Customer has many Contacts, ServiceLocations, and Jobs.
- Job belongs to one Customer and primary ServiceLocation; Job has many Visits.
- Visit belongs to one Job and one ServiceLocation; Visit has many assignments, time entries, media, part proposals, and at most one active submitted/approved closeout version.
- A Job may have many Visits but one continuous history.
- A Visit cannot be approved without required closeout evidence.
- A Visit with `needs_return_trip` cannot close the Job or create a billing handoff as complete.
- Only an approved closeout can create one BillingHandoff; retries must be idempotent.

Use foreign keys, explicit enums/value objects, database indexes for queue queries, and organization scoping on every tenant-owned record.

---

## 5. Lifecycle and transition rules

Implement transitions in a single domain/service layer. Controllers, jobs, and UI components must call the same transition rules.

### Job lifecycle

```text
open → on_hold → open
open → completed
open → canceled
```

A Job may become completed only after the required operational closeout and review rules pass. Billing handoff is separate.

### Visit lifecycle

```text
planned → scheduled → assigned → en_route → on_site
on_site → pending_closeout
pending_closeout → approved
pending_closeout → returned_for_correction
pending_closeout → customer_unavailable
pending_closeout → canceled
```

A submitted visit can produce:

- **Resolved:** eligible for review and, after approval, Job completion/billing handoff.
- **Needs return trip:** requires reason, unfinished work, needed parts/equipment, and recommended follow-up. Job remains open; office can schedule the next Visit.
- **Customer unavailable:** requires reason and office disposition.

Every transition records actor, timestamp, prior state, new state, and safe metadata in AuditEvent. Reject invalid transitions with a user-readable error and no partial writes.

---

## 6. Build phases

Each phase must be a small, reviewable PR. Codex must not implement the full plan in one pass.

### Phase 0 — Repository and runtime foundation

**Goal:** Make the new repo reliably runnable before domain features.

Build:

- Project README with product charter and scope lock.
- Environment example without secrets.
- Local setup, test, lint/format, asset build, queue, and migration commands.
- CI for tests, formatting, static checks, and build.
- Base application shell with organization scope.
- Authentication and session security.
- Host/subdomain detection with local-development override.
- Field shell and office shell, each with a clear empty state.
- Shared design tokens/components; mobile-first responsive baseline.
- Error, loading, empty, unauthorized, and connectivity states.

Acceptance gate:

- Fresh developer setup works from README.
- CI runs on a clean checkout.
- Authenticated user can enter both hosts if authorized.
- Super Admin without a technician profile can open and inspect the field shell.
- No domain module appears in navigation yet except the foundation placeholders.

### Phase 1 — Customer and service-location foundation

**Goal:** Establish the minimum customer context required for field work.

Build:

- Customer list/search/create/edit/detail.
- Contact management.
- Service-location list/create/edit/detail.
- Primary contact and access instructions.
- Organization scoping and authorization policies.
- Customer/location links from both field and office shells.
- Search that supports customer name, phone/email, address, and job number once jobs exist.

Acceptance gate:

- Office can create a customer with multiple locations.
- Field user can find the correct customer/location quickly on a phone.
- No cross-organization access is possible.
- Super Admin can view records regardless of TechnicianProfile.

### Phase 2 — Job and visit control plane

**Goal:** Create the operational records and dispatch lifecycle.

Build:

- Create Job from customer/location.
- Job detail with status, history, visits, contacts, and notes.
- Create/schedule Visit with a time window.
- Assign one or more users.
- Office dispatch queue/calendar with mobile-friendly fallback list.
- Field “Today” queue and upcoming visits.
- En Route and On Site transitions with timestamps.
- Return-trip creation under the same Job.
- Transition audit history.

Acceptance gate:

- One job can have two visits without duplicate customer history.
- Assigned technician sees only authorized assigned work by default.
- Dispatcher can move a visit through scheduling and assignment.
- Super Admin can inspect any visit and test field navigation.
- Invalid transitions are blocked consistently in UI and backend.

### Phase 3 — Mobile field execution

**Goal:** Make the technician workflow usable in the field.

Build:

- Mobile visit detail with customer/location/access context.
- Persistent current-status action.
- Work performed, diagnosis, exceptions, and recommendations.
- Travel and on-site time capture.
- Private photo capture/upload with category and progress.
- Customer acknowledgment/signature or required unavailable reason.
- Parts-used proposal capture, with no inventory mutation.
- Save-draft behavior with explicit success/failure feedback.
- Field history for the current Job.

Acceptance gate:

- A technician can complete a visit with minimal typing and no desktop-only control.
- Photos are private and visible only to authorized users.
- Failed upload/save never presents as successful.
- A closeout cannot be submitted with missing required evidence.
- Field user can mark Needs Return Trip without closing the Job.

### Phase 4 — Closeout review and office handoff

**Goal:** Connect field evidence to office action without rebuilding finance.

Build:

- Office review queue for submitted closeouts.
- One closeout packet: timeline, time, notes, media, acknowledgment, parts proposals, and outcome.
- Approve, return for correction, and resubmission.
- Immutable submitted versions; corrections create a new version.
- Separate reviewer adjustments from technician-submitted data.
- Idempotent BillingHandoff after approval when the outcome is billable/complete.
- Job/visit history showing who did what and when.
- Clear “ready for billing” state, with no invoice UI required yet.

Acceptance gate:

- Reviewer can understand the work without opening five unrelated screens.
- Return-to-tech includes a required reason and preserves prior evidence.
- Approval cannot be duplicated by refresh/retry.
- BillingHandoff points to exactly one approved closeout version.
- Needs Return Trip never creates a billing-ready completion handoff.

### Phase 5 — Beta hardening and workflow validation

**Goal:** Validate the experience before adding modules.

Build:

- Seed/demo scenario covering multiple customers, locations, visits, return trip, correction, and approval.
- Role/capability matrix and authorization tests.
- Mobile viewport QA and keyboard/accessibility checks.
- Performance checks for Today queue, dispatch queue, job detail, and media.
- Backup/restore and migration rehearsal.
- Observability for failed transitions, uploads, queue jobs, and handoffs.
- Product decision log for every requested feature deferred from the scope.

Beta gate:

Jonathan completes at least three realistic scenarios:

1. Resolved visit with photos and acknowledgment.
2. Needs Return Trip followed by a second visit.
3. Reviewer returns a closeout, technician resubmits, and reviewer approves.

The foundation is complete only when these scenarios are understandable, repeatable, and auditable on the field host and office host.

---

## 7. UI and navigation requirements

### Field host navigation

Keep the primary field navigation to:

- Today
- Jobs
- Customers
- Notifications/Tasks (only if implemented)
- Profile/Help

A technician should reach the next required action in one or two taps. Do not put proposals, finance, catalog administration, roadmap, or settings in the field primary navigation.

### Office host navigation

Keep the first office navigation to:

- Overview / Exceptions
- Customers
- Jobs
- Dispatch
- Closeout Review
- Billing Handoff
- Settings (limited foundation settings)

Do not add proposal, finance, project management, or analytics navigation until their phases are approved.

### Shared record design

Job detail is the center of gravity. It should show:

- Customer and service location.
- Current Job status.
- Next required action.
- Visits and their state.
- Assigned people.
- Field evidence/closeouts.
- Activity/audit history.
- Billing handoff state.

Avoid forcing users to navigate from Customer → Ticket → Visit → Invoice across disconnected screens to understand one piece of work.

---

## 8. Security, privacy, and authorization requirements

- Scope every query by organization and verify object ownership through policies.
- Do not trust host selection, hidden fields, or client-side role flags.
- Enforce permissions server-side and mirror them in UI affordances.
- Keep media private; use authorized download/preview endpoints or signed URLs with short lifetime.
- Do not expose customer contact details or site access instructions to unauthorized users.
- Technicians may access assigned visits by default; explicit capabilities may broaden access for dispatchers/reviewers/admins.
- A technician cannot approve their own closeout unless an owner-approved test capability explicitly allows it; record that action.
- Super Admin field access must work without a technician profile.
- Record security-sensitive transitions in the audit trail.
- Never log credentials, signatures, private media URLs, or sensitive customer data unnecessarily.

---

## 9. Testing and quality gates

Every phase must include:

### Automated tests

- Model relationships and organization scoping.
- Policy/capability matrix, including Super Admin without TechnicianProfile.
- Valid and invalid lifecycle transitions.
- Return-trip behavior.
- Closeout required fields and version immutability.
- Private media authorization.
- Duplicate submission/approval/handoff idempotency.
- Host-aware route/access behavior.
- Mobile/API/form validation.

### Manual QA

- Phone viewport: small Android/iPhone width.
- Slow network and failed upload behavior.
- Keyboard navigation and readable focus states.
- Office desktop workflow.
- Same record viewed from both hosts.
- Refresh/retry during transition and upload.
- Two visits under one Job.
- Super Admin entering field view without TechnicianProfile.

### Required commands

The repo README must define exact commands for:

- Install/setup.
- Database migration and seed.
- Unit/feature tests.
- Formatting/lint/static analysis.
- Asset build.
- Local field and office host access.
- Queue worker/scheduler if used.

Codex must run the commands from a clean checkout before claiming a phase complete and include results in the PR.

---

## 10. Codex execution protocol

1. Inspect the new repository and confirm its actual framework, default branch, package manager, and local run commands. Do not assume the old repo’s setup.
2. Add this plan as `docs/FSM_FIRST_CODEX_HANDOFF.md` and add a concise `README.md` pointer.
3. Create one branch per phase: `codex/foundation`, `codex/customer-locations`, `codex/jobs-visits`, `codex/mobile-execution`, `codex/closeout-review`, `codex/beta-hardening`.
4. Before coding each phase, write a short affected-files/schema plan in the PR description.
5. Keep migrations additive and reversible. Never copy production data or secrets into the new repo.
6. Prefer one canonical lifecycle service/domain layer. Do not duplicate transition rules across field and office controllers.
7. Use feature flags or route gating when a phase is incomplete; unfinished features must not appear as if shipped.
8. Open a draft PR for each phase with scope, exclusions, schema changes, authorization, tests, manual QA, and rollback notes.
9. Do not merge, deploy, delete the old beta repo, or claim production readiness without Jonathan’s explicit approval.
10. At every phase gate, test the actual field and office hosts locally and record the exact result.

### First Codex task

Do not begin with customer CRUD. First perform Phase 0 repository verification and commit:

- Product charter and scope lock.
- README and local run instructions.
- CI and quality commands.
- Host-aware field/office shell.
- Shared auth/capability foundation.
- Super Admin field-access test without TechnicianProfile.

Only after that gate passes should Codex create Customer and ServiceLocation tables.

---

## 11. Definition of done for the foundation

The new FSM foundation is done when:

- A clean checkout runs locally using documented commands.
- Field and office hosts are visually and operationally distinct but share one database and identity.
- Super Admin can enter both hosts without needing a TechnicianProfile.
- Customer, location, Job, and Visit records are connected and organization-scoped.
- A visit can move from planned through field execution to submitted closeout.
- Photos, time, notes, acknowledgment, and outcome are captured privately and audibly.
- A reviewer can approve or return a closeout without losing history.
- Return trips remain under the original Job.
- Approval creates one billing-ready handoff and does not pretend to be a full finance system.
- Three realistic beta scenarios pass on mobile and desktop.
- CI, focused tests, manual QA, migration rehearsal, and rollback notes are complete.

Only then should the owner decide whether to add proposals, contracts, projects, inventory, or finance.

---

## 12. Decision log placeholders

Codex should ask for an explicit owner decision only where the plan intentionally leaves a product choice open:

- Exact new repository name and hosting/runtime target.
- Framework choice if the new repo is not already established.
- Authentication provider and local development host strategy.
- Signature provider or whether first release uses acknowledgment only.
- Required closeout fields for NewDay’s first service type.
- Whether billing handoff should emit an event, persist a record, or both.
- Production hostname and deployment target after beta validation.

Do not block the foundation on future proposal, finance, contract, or analytics decisions.
