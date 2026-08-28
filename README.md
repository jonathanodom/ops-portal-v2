# NewDay Tech Ops Portal v2

An FSM-first operations portal for NewDay Tech. The product is intentionally narrow:

```text
Customer → Service Location → Job → Visit → Field Execution → Closeout → Office Review → Billing Handoff
```

Phase 0 establishes authentication, organization-scoped authorization, and distinct office and field experiences.
Phase 1 adds the narrow customer, contact, and service-location foundation required for field work.
Phase 2 adds service tickets, visits, dispatch scheduling, crew assignment, and field Today workflows.
Phase 3 adds mobile time capture, shared closeout drafts, private evidence, parts/equipment proposals, acknowledgment, and evidence-validated submission.
Phase 4 adds immutable correction versions, office review and adjustments, operational approval, Service Ticket completion, and billing handoffs.
Phase 5 adds an isolated beta rehearsal environment, operational health diagnostics, guarded recovery tools, accessibility coverage, and performance regression budgets.

See [the complete FSM-first handoff](docs/FSM_FIRST_CODEX_HANDOFF.md) for the product charter, lifecycle, scope lock, phased delivery plan, and quality gates.

## Requirements

- PHP 8.4 and Composer 2
- Node.js 22 and npm
- Docker Desktop with Compose for local MySQL 8.4

## First-time setup

```powershell
composer setup
```

This installs dependencies, creates `.env`, starts MySQL on local port `3307`, migrates and seeds access-control records, and builds frontend assets.
Run `composer setup` only when creating a new local environment.

Create the first Super Admin:

```powershell
php artisan portal:create-user owner@newdaytech.net --name="Jonathan Odom" --role=super_admin
```

The command prompts securely for a password. Do not put real credentials in command history, source control, seeders, or documentation.

## Update between phases without losing data

After pulling a new phase, run:

```powershell
composer phase:update
```

This reuses the existing `newday_mysql` Docker volume, applies only pending migrations, runs idempotent system seeders, and rebuilds dependencies/assets. It does not recreate tables or remove users, customers, contacts, or service locations.

Do not run `migrate:fresh`, `db:wipe`, or `docker compose down -v` against the local development database. Those commands intentionally destroy data. The automated test suite uses a separate disposable test database and may rebuild that database safely.

See [Local data preservation](docs/LOCAL_DATA_PRESERVATION.md) for the phase-by-phase workflow, migration rules, backup guidance, and recovery boundaries.

## Run locally

```powershell
composer dev
```

Open:

- Office: `http://localhost:8000/office`
- Field: `http://localhost:8000/field`
- Health: `http://localhost:8000/up`

Local password-reset mail is written to the Laravel log. Production mail and runtime secrets must be configured outside the repository.
Production must also set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://portal.newdaytech.net`, and `SESSION_SECURE_COOKIE=true`.

## Phase 1 customer directory

- Office: `/office/customers` and `/office/locations`
- Field: `/field/customers`
- Super Admin and Dispatcher can create and update records.
- Reviewer and Billing have office read access.
- Technician has a read-only field directory containing active operational details only.
- Customer removal is archive-only: customer status becomes inactive and contacts/locations use their active flag.
- Customer creation saves the customer, optional preferred contact, and required first service location in one transaction.

All customer records are created fresh in v2. There is no beta-data import, external CRM synchronization, address validation, or geocoding in this phase.

## Phase 2 service tickets and visits

- Office: `/office/service-tickets` and `/office/dispatch`
- Field: `/field` for Today and the next seven days
- Service-ticket identifiers use the organization/year sequence `NDT-ST-YYYY-0123`.
- Super Admin and Dispatcher manage tickets, scheduling, assignments, and exceptions.
- Reviewer and Billing receive read-only office access.
- Assigned Technicians can move authorized visits from Assigned to En Route and On Site.
- Super Admin receives every system capability, including unrestricted visit execution; other roles remain assignment/capability scoped.
- Visit windows are entered in the service-location timezone and stored in UTC.
- Internal ticket notes remain office-only and append-only.

See [Phase 2 validation](docs/PHASE_2_VALIDATION.md) for schema changes, lifecycle rules, authorization, validation results, and exclusions.

## Phase 3 mobile field execution

- Assigned execution-capable crew share one optimistic-locking closeout draft.
- Individual travel, on-site, and other timers enforce one active timer per user.
- Only the assigned lead submits unless the membership has `visits.execute_any`; Super Admin receives that audited override by role.
- Resolved work requires categorized photo evidence or a categorized no-photo fallback.
- Private evidence is streamed through authorized endpoints and never receives a public storage URL.
- Submitted narrative, time, media, and proposals remain immutable until Phase 4 corrections.
- Office users see draft status and time totals; full submitted evidence requires `closeouts.inspect`.

See [Phase 3 validation](docs/PHASE_3_VALIDATION.md) for the schema, authorization/evidence matrices, storage settings, local preservation record, validation results, rollback notes, and exclusions.

## Phase 4 closeout review and billing handoff

- Office Review: `/office/closeout-reviews` for submitted evidence, version comparison, time/part adjustments, approval, and return-for-correction.
- Returned closeouts create a new editable version while preserving earlier time, photos, acknowledgment, and decisions as immutable evidence.
- Reviewer self-approval is denied except for an explicitly audited Super Admin override.
- Approved resolved work completes its Service Ticket only when no active follow-up remains, then creates one idempotent ready billing handoff.
- Billing: `/office/billing-handoffs` exposes effective approved totals and acknowledgment without granting private field-evidence access.
- Non-resolved approvals retain operational state and never create a billing handoff.

See [Phase 4 validation](docs/PHASE_4_VALIDATION.md) for schema changes, decision matrices, preservation evidence, exact validation results, rollback notes, and exclusions.

## Phase 5 beta hardening

Copy `.env.beta.example` to the untracked `.env.beta`, set a local-only `BETA_DEMO_PASSWORD`, then run:

```powershell
composer beta:setup
composer beta:serve
composer beta:validate
php artisan beta:benchmark --env=beta --runs=20 --fail-on-budget
php artisan ops:health-scan
```

The beta reset guard requires `APP_ENV=beta`, a database name/path containing `beta`, and—when using SQLite—a path inside this repository's `database` directory. It cannot reset the normal development database. The beta seeders are intentionally absent from `DatabaseSeeder`.

See the [Phase 5 beta runbook](docs/PHASE_5_BETA_RUNBOOK.md), [validation record](docs/PHASE_5_VALIDATION.md), and [deferred decision log](docs/PHASE_5_DECISION_LOG.md). Phase 5 remains a local/CI gate and makes no production-readiness or deployment claim.

Phase 6 invoicing architecture, calculation rules, authorization, recovery, and acceptance gates are documented in [Phase 6 Invoicing](docs/PHASE_6_INVOICING.md).

Organization identity, timezone, private branding, Billing defaults, and Invoice defaults are managed through the route-backed Office Settings area. See [Master Organization Settings](docs/ORGANIZATION_SETTINGS.md) for canonical-data rules, authorization, storage retention, preservation evidence, and rollback guidance.

Phase 7 payment architecture, encrypted Square/Stripe configuration, provider locking, hosted checkout, immutable ledger rules, reconciliation, and customer-safe receipts are documented in [Phase 7 Payments](docs/PHASE_7_PAYMENTS.md).

Phase 8.6 provider-hosted Square/Stripe connection, canonical webhooks, default-processor resolution, Billing workspace refinements, final validation results, and Sandbox/test-account acceptance steps are documented in [Connected Payments & Billing Workspace](docs/PHASE_8_6_CONNECTED_PAYMENTS_BILLING_WORKSPACE.md).

## Phase 8 Products & Services Catalog

Checkpoints 1 through 5 add organization-scoped Categories, reusable Units of Measure, Services, explicit Service Variants, optional related-Service add-ons, Products, base/sales units, Product-specific purchase conversions, Packages, standard Product/Service recipes, explainable pull-count allowances, optional waste, deterministic demand calculation, field/Invoice Catalog selection, immutable transaction snapshots, and recurring Customer Service enrollment tracking. Inventory quantities and recurring billing automation remain future work.

See [Catalog Architecture](docs/CATALOG_ARCHITECTURE.md) for pricing models, UOM boundaries, permissions, audit behavior, historical snapshot plans, and the future Inventory/Purchasing extension boundary.

See [Phase 8 Checkpoint 2](docs/PHASE_8_CHECKPOINT_2.md) for Product schema, wire conversion examples, preservation evidence, exact validation results, and rollback notes.

See [Phase 8 Checkpoint 3](docs/PHASE_8_CHECKPOINT_3.md) for Package schema, the Integrated Smart Home TV Rough-In acceptance case, standard-versus-actual boundaries, preservation evidence, and rollback notes.

See [Phase 8 Checkpoint 4](docs/PHASE_8_CHECKPOINT_4.md) for field and Invoice selection, snapshot provenance, authorization, privacy, local preservation, and validation results.

See [Phase 8 Checkpoint 5](docs/PHASE_8_CHECKPOINT_5.md) for recurring Customer Service enrollment scope, immutable commercial snapshots, lifecycle rules, automation boundaries, preservation evidence, and validation results.

Phase 8.7 makes Catalog authoritative for new labor and trip-charge pricing while Billing Settings owns time-calculation policy. See [Catalog-Aligned Labor Billing](docs/PHASE_8_7_CATALOG_ALIGNED_LABOR_BILLING.md) for Catalog codes, rounding and minimum-time behavior, trip review/provenance, legacy compatibility, production bootstrap commands, preservation evidence, and validation results.

Release Candidate V1.0 validation, data-preservation evidence, lifecycle changes, mobile before/after review, rollback notes, and the manual production acceptance gate are documented in [Release Candidate V1.0](docs/RELEASE_CANDIDATE_V1_0.md).

## Commercial Operations V1 architecture

The next additive product track connects Customers and Catalog to Opportunities, revisioned Quotes, customer-facing Proposals, acceptance, Change Orders, Project conversion, material/labor planning, and milestone Billing. The approved product decisions, bounded-context rules, proposed schema, authorization model, calculation order, implementation checkpoints, and field-test isolation requirements are documented in [Commercial Operations V1 Architecture](docs/COMMERCIAL_OPERATIONS_V1_ARCHITECTURE.md). The document is an implementation contract only; it does not authorize deployment or change the current field lifecycle.

Commercial Operations Phase 2 adds the internal revisioned Quote builder, immutable Catalog and Package recipe snapshots, deterministic estimating calculations, revision-owned dimensions, options, Allowances, and payment schedules. See [Commercial Operations Phase 2](docs/COMMERCIAL_OPERATIONS_PHASE_2.md) for its schema, calculation rules, authorization, preservation evidence, UI checklist, and exclusions.

Commercial Operations Phase 3 adds approved Service/labor-role estimating costs, fixed and component-sum Package pricing, immutable cost provenance, and an authorized transactional Quote-to-Catalog item overlay. See [Commercial Operations Phase 3](docs/COMMERCIAL_OPERATIONS_PHASE_3.md) for the schema, resolution order, preservation evidence, UI checklist, and deferred Checkpoint 4 boundary.

The implemented Opportunity foundation and its Phase 1 owner acceptance gate are documented in [Commercial Operations Phase 1](docs/COMMERCIAL_OPERATIONS_PHASE_1.md).

## Quality commands

```powershell
php artisan migrate --seed
php artisan test
vendor/bin/pint --test
npm ci
npm run build
composer check
```

CI repeats the migrations, seed, tests, formatting check, and production asset build against MySQL 8.4.

## Vultr production deployment

The deployment scripts are deliberately separate:

1. **Initial install** — clone the repository and create a production `.env`:

   ```bash
   sudo -u viktor-deploy APP_DIR=/var/www/ops-portal-v2 \
     GIT_REPO_URL=https://github.com/jonathanodom/ops-portal-v2.git \
     ./scripts/install-production.sh
   sudoedit /var/www/ops-portal-v2/.env
   /var/www/ops-portal-v2/scripts/update-production.sh
   ```

   The install script refuses to overwrite an existing directory and does not
   write real credentials. Set the database, mail, session, and application
   values in `.env` before the first update.

2. **Sequential updates** — run the same update command after an approved merge:

   ```bash
   /var/www/ops-portal-v2/scripts/update-production.sh
   ```

   Updates use fast-forward-only Git pulls, locked Composer/npm dependencies,
   production asset builds, additive Laravel migrations, idempotent seeders,
   cache rebuilds, and queue restarts. They never run `migrate:fresh`,
   `db:wipe`, `migrate:reset`, or remove Docker/database volumes.

3. **Nightly update** — install the cron entry once:

   ```bash
   sudo APP_DIR=/var/www/ops-portal-v2 \
     /var/www/ops-portal-v2/scripts/install-nightly-update.sh
   ```

   This runs daily at 02:17 server time, uses `flock` to prevent overlapping
   deployments, and writes output to `storage/logs/nightly-deploy.log`.

The nightly job is intentionally a deployment check, not a backup. Configure
and verify independent encrypted database and storage backups before enabling
automatic production updates. Keep the app's `.env`, uploaded files, and
database outside Git.

## Safety boundaries

- `jonathanodom/Ops-portal` and `portal.newdaytech.net` are read-only references.
- Do not copy production data, credentials, media, or secrets into v2.
- Do not deploy, merge, or cut over production without explicit owner approval.
- All tenant-owned records must be scoped through the active organization membership.
- A TechnicianProfile is optional and never grants field access by itself.
- Merged migrations are immutable. Every later schema change uses a new additive, reversible migration.
