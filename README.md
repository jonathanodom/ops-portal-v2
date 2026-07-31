# NewDay Tech Ops Portal v2

An FSM-first operations portal for NewDay Tech. The product is intentionally narrow:

```text
Customer → Service Location → Job → Visit → Field Execution → Closeout → Office Review → Billing Handoff
```

Phase 0 establishes authentication, organization-scoped authorization, and distinct office and field experiences. It does not include customer, job, visit, proposal, inventory, invoice, or finance features.

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

Create the first Super Admin:

```powershell
php artisan portal:create-user owner@newdaytech.net --name="Jonathan Odom" --role=super_admin
```

The command prompts securely for a password. Do not put real credentials in command history, source control, seeders, or documentation.

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

## Quality commands

```powershell
php artisan migrate:fresh --seed
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
