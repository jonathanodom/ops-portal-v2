# Local Development Data Preservation

The local development database is cumulative across phases. Users, memberships, customers, contacts, locations, and future FSM records should remain available while the application grows.

## Start a new phase

Run Git commands inside the `ops-portal-v2` repository:

```powershell
git switch main
git pull --ff-only origin main
git switch -c codex/<phase-name>
composer phase:update
```

`composer phase:update` performs these non-destructive steps:

1. Installs the locked Composer dependencies.
2. Starts the existing MySQL container and named `newday_mysql` volume.
3. Waits for MySQL to accept connections.
4. Runs `php artisan migrate --force`, which applies only pending migrations.
5. Runs `php artisan db:seed --force` for idempotent system records such as roles and capabilities.
6. Installs locked frontend dependencies and builds production assets.

Use `composer dev` after the update to run the portal.

## Data-safety rules

- Never run `php artisan migrate:fresh`, `php artisan db:wipe`, or `php artisan migrate:reset` against the local development database.
- Never run `docker compose down -v`; the `-v` option removes the persistent MySQL volume.
- `docker compose stop` and `docker compose down` without `-v` preserve the named volume.
- Never edit a migration after it has merged. Add a new reversible migration for each schema change.
- Seeders used by `DatabaseSeeder` must be idempotent. They may create or update system configuration but must not truncate tenant tables or replace users/customer data.
- Demo customers and users do not belong in the default seeder. Create durable local records through the portal or `portal:create-user`.
- Keep tests pointed at the isolated test database from CI/PHPUnit. Test refreshes must never target the local development database.

## Before a risky schema migration

Normal additive migrations do not require re-entering data. Before an exceptional migration that transforms or removes columns, record a local backup and document its restore command in that phase's PR.

For the Docker MySQL service, create the backup directory and export a UTF-8 SQL dump:

```powershell
New-Item -ItemType Directory -Force storage/app/backups | Out-Null
docker compose exec -T mysql mysqldump --single-transaction --quick --lock-tables=false -unewday -pnewday_local_only newday_ops |
    Set-Content -Encoding utf8 storage/app/backups/newday_ops_before_phase.sql
```

Backups under `storage/` remain local and are excluded from source control. They may contain private customer data and must never be committed or shared.

## Intentional reset

Only reset the local database when the owner explicitly approves losing all local development records. Before resetting, create and verify a backup. A reset is not part of normal phase setup, validation, or branch switching.
