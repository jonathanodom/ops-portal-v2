# Commercial Operations V1 — Codex Handoff for Phases 0–3

## Purpose

This handoff authorizes implementation of **Commercial Operations V1 Checkpoints 0 through 3 only**. In this handoff, “Phase 0,” “Phase 1,” “Phase 2,” and “Phase 3” mean the Commercial Operations checkpoints with the same numbers. They do not refer to the older FSM phases described elsewhere in the README.

Build the four phases sequentially on one feature branch. After each phase, stop completely so Jonathan can run local UI acceptance testing. Do not begin the next phase, prepare its migrations, or make opportunistic next-phase changes until Jonathan responds with `continue` or another unambiguous approval to proceed.

## Authoritative inputs

Read these files completely before changing code:

1. `README.md`
2. `docs/COMMERCIAL_OPERATIONS_V1_ARCHITECTURE.md`
3. This handoff

The architecture document is the product and technical implementation contract. This handoff controls sequencing, branch discipline, pause gates, and the required phase reports. The current repository code is implementation truth for existing contracts and conventions. If the documents conflict with current code in a way that changes product behavior, security, data preservation, or scope, stop and ask Jonathan instead of guessing.

Do not silently broaden or rewrite the approved scope. Record useful implementation discoveries in the current phase report and propose architecture amendments separately.

## Branch and delivery contract

- Start from repository `main` at the documented architecture baseline, `02e789781376e1e8236893f78ccc04d939c7efb0`, after confirming the local worktree and remote state.
- Use one feature branch for the entire four-phase build: `feat/commercial-operations-v1-checkpoints-0-3`.
- Keep Phases 0–3 on that branch. Do not create phase branches and do not merge a phase into `main` between owner test gates.
- Before starting each phase, confirm the active branch and show the current commit SHA.
- Keep commits phase-scoped and reviewable. Do not squash, rebase, force-push, merge, deploy, or open a production cutover without explicit owner direction.
- Preserve owner changes and unrelated dirty-worktree changes. Never discard, overwrite, or “clean up” work that is outside the active phase.
- The deployed field-test SHA is a separate reference from the Commercial feature branch. Record it during Phase 0; never move or redeploy the field-test environment as part of this handoff.

If the uploaded architecture or README is newer than the repository copy, report the difference before implementation and use Jonathan’s direction to establish the authoritative version.

## Non-negotiable safety and compatibility rules

- This is additive feature work. Existing Customers, Catalog, Projects, Service Tickets, Visits, field execution, Closeouts, Review, Billing Handoffs, Invoices, Payments, files, and organization settings must remain intact.
- Reuse the integration contracts named in the architecture. Do not create competing Customer, Catalog, Project, Ticket, Invoice, authorization, audit, numbering, file, or signature implementations.
- Every tenant-owned query, command, policy, job, route, and identifier is scoped to the active organization.
- Current merged migrations are immutable. New migrations must be additive and reversible.
- Do not run `migrate:fresh`, `db:wipe`, `migrate:reset`, `docker compose down -v`, or any command that destroys retained local or production data.
- Do not replace `.env`, expose secrets, use production data, or copy private production media.
- Do not deploy or modify a live database.
- Use the repository’s existing authorization model and explicit capabilities. Hiding a UI control is not authorization.
- Preserve historical Catalog and transaction snapshots. A later Catalog edit must not mutate an existing Quote revision, Invoice line, field proposal, or accepted record.
- Financial calculations use integer minor units and deterministic allocation/rounding. Do not use binary floating-point money arithmetic.
- Do not claim totals, margin, or approval status when required cost inputs are unresolved. Mark the value as unresolved and make the missing input visible.
- Follow existing Office workspace patterns, accessibility conventions, responsive behavior, tests, services, policies, jobs, and audit patterns before introducing a new abstraction.

## Execution protocol for every phase

For each phase, Codex must follow this sequence:

1. **Inspect** — confirm the branch/SHA, reread the phase scope and related architecture sections, inspect existing implementation contracts, and check for owner changes.
2. **State the phase plan** — identify intended migrations, models, services, policies, routes, UI surfaces, tests, and explicit exclusions before editing.
3. **Implement only the active phase** — keep the change set inside its approved boundary.
4. **Validate** — run focused tests, full regression, formatting/static checks, production asset build, migration checks, and relevant accessibility/responsive checks. Use MySQL where the architecture requires it.
5. **Prepare local testing** — preserve the existing local database, give exact update/start commands, list routes and roles, and provide any safe test-data setup needed for owner review.
6. **Report and stop** — use the required end-of-phase report below and wait. Do not work ahead while waiting.
7. **Address feedback in the same phase** — fix owner-reported defects on the same branch, rerun affected validation, issue a revised report, and pause again.
8. **Continue only after approval** — begin the next phase only after Jonathan explicitly responds with `continue` or clearly approves the phase and directs continuation.

The normal retained-data update path is:

```powershell
composer phase:update
composer dev
```

Use `composer setup` only for a genuinely new local environment. The owner UI review should use the retained local database unless Jonathan directs otherwise.

## Phase 0 — Baseline and characterization

### Goal

Protect the deployed FSM, Catalog, Projects, Billing, and security behavior before Commercial Operations adds schema or routes.

### Required implementation

- Record the repository baseline and the exact deployed field-test SHA separately. If the deployed SHA cannot be verified from available evidence, report it as an owner-confirmation item; do not guess.
- Inventory relevant existing routes, migrations, row-count/preservation checks, capabilities, policies, workflows, private-storage patterns, and test commands.
- Add characterization coverage for:
  - Catalog transaction snapshot creation;
  - Project creation and canonical Service Ticket linking;
  - direct Invoice creation;
  - `InvoiceCalculator` behavior and rounding;
  - private signature storage/delivery authorization; and
  - explicit authorization overrides and their audit behavior.
- Establish preservation evidence sufficient to detect unintended changes to existing records and field behavior.
- Confirm there are no Commercial Operations routes, migrations, jobs, navigation entries, or runtime side effects yet.

### Explicit exclusions

- No Commercial Operations schema.
- No Opportunity or Quote routes, navigation, models, or UI.
- No refactor whose only purpose is future-phase convenience.
- No changes to existing lifecycle behavior.

### Validation and owner gate

- Run the new characterization tests and the complete existing test/quality suite.
- Run or document MySQL 8.4 validation where required by the repository.
- Provide exact before/after preservation evidence and test results.
- Give Jonathan a local regression checklist for Office, Field, Catalog, Projects, Billing, Invoices, and any existing signature flow. This is the Phase 0 UI gate even though Phase 0 adds no new UI.
- Stop with: `WAITING FOR OWNER UI ACCEPTANCE — PHASE 0`.

## Phase 1 — Opportunity foundation

### Goal

Deliver the organization-scoped Opportunity workspace without beginning Quote construction.

### Required implementation

- Add Commercial settings and the one configurable pipeline with default stages:
  - New
  - Qualifying
  - Quoting
  - Presented
  - Won
  - Lost
- Add required capabilities, policies, navigation, document sequencing, Opportunity records, ownership, stage probability defaults with Opportunity override, assigned follow-up tasks, activity timeline, notes/calls/emails, files/attachments, and per-user view preferences.
- Customer is required; site is optional.
- Number Opportunities as `OPP-YYYY-NNNN` using the existing organization/year sequence contract.
- Opportunity activity includes stage and ownership changes, notes/calls/emails, files, task activity, and future-safe activity types for Quote/Proposal and conversion events.
- Opportunity stage rules:
  - ordinary users cannot manually set Presented or Won;
  - managers/admins may perform an explicit audited override;
  - Lost accepts an optional reason and note;
  - Lost may be reopened;
  - Won remains final.
- Deliver the Opportunities page with **Kanban as the default view** and a **List view option**. Persist each user’s last selected view.
- Kanban cards show customer/site, estimated or active-Quote value, Quote/Proposal status placeholder, and latest activity date. Until Quotes exist, pipeline value uses the Opportunity estimate.
- The Opportunity detail page exposes the approved foundation data, tasks, activity, and files without presenting nonfunctional Quote actions as complete.

### Explicit exclusions

- No Quote builder, Quote schema, Proposal publication, public link, acceptance, Project conversion, or deposit Invoice.
- No manual path for ordinary users to bypass protected Presented/Won stage transitions.

### Validation and owner gate

- Test organization isolation, capabilities, explicit denials, sequences/concurrency, stage rules/overrides, activity, tasks, files, view persistence, and preservation of existing domains.
- Review at 390, 768, 1280, 1440, and 1920px. Require no horizontal page overflow, usable keyboard/focus behavior, accessible labels, and at least 44px phone touch targets where applicable.
- Provide local test users/roles and safe Opportunity test-data instructions.
- Give Jonathan a UI checklist covering navigation, first load to Kanban, Kanban/List persistence, create/edit/detail, card contents, tasks, files, activity, manager overrides, Lost/reopen, permission denials, and responsive behavior.
- Stop with: `WAITING FOR OWNER UI ACCEPTANCE — PHASE 1`.

## Phase 2 — Quote and revision foundation

### Goal

Deliver the internal estimating workspace and immutable revision model. Customer publication remains out of scope.

### Required implementation

- Add organization-scoped commercial documents and revisions, hierarchical Quote locations, organization/default plus Quote-added Systems and Phases, sections, Catalog-backed lines, priced Allowances, optional lines, Package component snapshots, and payment schedules.
- Number Quote revisions as `Q-YYYY-NNNN-Vn` using the existing sequence contract.
- Support Products, Services, Packages, and priced Allowances. A non-Catalog sale item is not a free-form Quote line; its UI path belongs to Phase 3’s Catalog overlay.
- Show Location as the initial grouping and support the other architecture-approved views over the same line records.
- Support Catalog sell price, direct sell-price edit, markup calculation, margin calculation, line discount, Quote-level discount, customer tax exemption, taxability by line, and authorized manual tax override.
- Support optional lines selected before acceptance, priced Allowances, and Package snapshots that can be tailored for one Quote without changing the Catalog Package.
- Support fixed-amount or percentage payment milestones with deterministic allocation and remainder-cent handling.
- Implement deterministic sell/cost/discount/tax/margin calculation in integer minor units, with focused boundary tests. Phase 2 must preserve and expose unresolved cost inputs rather than inventing a cost; Phase 3 adds the remaining Service/labor-role defaults.
- Only Draft revisions are editable. Publishing is not implemented yet, but the model must support immutable non-Draft revisions, cloning to a new Draft revision, optimistic concurrency/stale-edit rejection, content hashes, and historical snapshot stability.
- Multiple Quotes may belong to one Opportunity.

### Explicit exclusions

- No publication, PDF/email delivery, customer link, customer response, signature, acceptance, automatic Presented/Won transitions, approval workflow, Project conversion, Change Order execution, or Invoice creation.
- Do not implement Phase 3’s Add Catalog Item overlay early.

### Validation and owner gate

- Test isolation, numbering and concurrent revision cloning, stale edits, Draft-only mutation, snapshot immutability, Catalog changes/deactivation, dimensions, line types, options, Allowances, Package snapshots, payment schedules, and the full financial calculation matrix.
- Include calculation cases for line and Quote discounts, tax exemption, line taxability, authorized override, markup/margin, optional-line inclusion, below-cost visibility, unresolved costs, and remainder cents.
- Review the Quote builder at all required viewports and with keyboard/accessibility tooling. Confirm dense desktop use and usable phone/tablet review without horizontal page overflow.
- Give Jonathan a UI checklist covering Quote creation, default Location grouping, alternate groupings, Catalog line selection, dimensions, quantities, pricing modes, discounts, tax, margin visibility permissions, options, Allowances, Package snapshot edits, milestones, cloning, and locked revision behavior.
- Stop with: `WAITING FOR OWNER UI ACCEPTANCE — PHASE 2`.

## Phase 3 — Catalog estimating extensions

### Goal

Complete the Catalog inputs required for reliable estimating without changing existing field or Invoice snapshot behavior.

### Required implementation

- Add hourly and fixed-price Service estimating support with Catalog or labor-role cost defaults as specified by the architecture.
- Add the approved labor-role cost-default model and authorization.
- Allow each Catalog Package to use fixed Quote price or component-sum Quote pricing.
- Preserve Package standard recipes while allowing a Quote revision to own and edit its Package component snapshot.
- Add the Quote builder’s **Add Catalog Item** overlay. Saving a proposed non-Catalog item must first create an authorized canonical Catalog Product, Service, or Package; only after that save succeeds may the new Catalog item be added to the Quote revision.
- Reuse existing Catalog validation, organization scoping, categories/UOMs/variants/recipes, audit behavior, and permissions. Unauthorized users cannot bypass Catalog create authorization from the Quote builder.
- Resolve Phase 2’s supported Service/labor cost inputs and recalculate affected Draft revisions deterministically. Historical locked snapshots remain unchanged.

### Explicit exclusions

- No arbitrary custom/non-Catalog Quote lines.
- No Inventory, purchasing, supplier, PO, quantity-on-hand, reservation, or receiving records.
- No publication, customer acceptance, conversion, Change Order processing, or deposit Invoice.
- No mutation of existing field proposals, Invoice snapshots, Package standard recipes, or historical Quote revisions.

### Validation and owner gate

- Test cost resolution, hourly/fixed Services, labor roles, Package pricing modes, component-sum behavior, authorization, overlay transaction rollback, duplicate/invalid Catalog entries, historical immutability, and organization isolation.
- Rerun the existing field Catalog selector, Package-demand, direct Invoice, Invoice calculation, and transaction-snapshot regression suites.
- Give Jonathan a UI checklist covering Service/labor cost configuration, Package pricing selection, component editing, Add Catalog Item overlay for each supported type, validation/duplicates, permission denial, recalculation, and field/Invoice regressions.
- Stop with: `WAITING FOR OWNER UI ACCEPTANCE — PHASE 3`.
- Do not begin Checkpoint 4. End with a consolidated Phase 0–3 status and a clearly separated list of deferred Checkpoint 4 work.

## Required end-of-phase report

At every pause, provide one self-contained report with:

1. **Phase and branch** — active branch, starting SHA, ending SHA, and deployed field-test SHA reference.
2. **Delivered scope** — user-visible behavior and internal contracts completed.
3. **Changed files** — grouped by migrations, domain/services, authorization, routes/UI, tests, and docs.
4. **Database impact** — migrations, reversibility, retained-data handling, and preservation evidence.
5. **Validation evidence** — exact commands, pass/fail counts, MySQL result, formatting/build result, accessibility/responsive result, and any skipped check with reason.
6. **Local run instructions** — exact non-destructive update/start commands, routes, roles, and safe test-data steps.
7. **Owner UI checklist** — numbered, observable tests with expected results.
8. **Known issues and exclusions** — no hidden deferrals.
9. **Gate statement** — the exact waiting statement for the completed phase.

Do not describe a phase as complete if required validation is failing or unavailable. State the blocker and remain in the same phase.

## Initial Codex instruction

Use the following as the first build instruction after uploading this handoff, the architecture, and the README:

> Read `README.md`, `docs/COMMERCIAL_OPERATIONS_V1_ARCHITECTURE.md`, and `docs/COMMERCIAL_OPERATIONS_PHASE_0_3_CODEX_HANDOFF.md` completely. Confirm the repository baseline and create or switch to the single feature branch `feat/commercial-operations-v1-checkpoints-0-3`. Implement Commercial Operations Phase 0 only. Follow all characterization, preservation, validation, reporting, and stop-gate requirements. Do not begin Phase 1 until I finish local UI regression testing and respond with `continue`.
