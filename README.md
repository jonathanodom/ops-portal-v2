# NewDay Tech Ops Portal v2

An FSM-first operations portal for NewDay Tech. The product is intentionally narrow:

```text
Customer → Service Location → Job → Visit → Field Execution → Closeout → Office Review → Billing Handoff
```

Phase 0 establishes authentication, organization-scoped authorization, and distinct office and field experiences.
Phase 1 adds the narrow customer, contact, and service-location foundation required for field work.
Phase 2 adds service tickets, visits, dispatch scheduling, crew assignment, and field Today workflows.

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
- `visits.inspect_all` never grants execution; `visits.execute_any` remains an explicit override.
- Visit windows are entered in the service-location timezone and stored in UTC.
- Internal ticket notes remain office-only and append-only.

See [Phase 2 validation](docs/PHASE_2_VALIDATION.md) for schema changes, lifecycle rules, authorization, validation results, and exclusions.

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

## Safety boundaries

- `jonathanodom/Ops-portal` and `portal.newdaytech.net` are read-only references.
- Do not copy production data, credentials, media, or secrets into v2.
- Do not deploy, merge, or cut over production without explicit owner approval.
- All tenant-owned records must be scoped through the active organization membership.
- A TechnicianProfile is optional and never grants field access by itself.
- Merged migrations are immutable. Every later schema change uses a new additive, reversible migration.
