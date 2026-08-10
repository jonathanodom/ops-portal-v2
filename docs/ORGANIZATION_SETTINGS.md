# Master Organization Settings Foundation

## Scope

The route-backed Office Settings area provides three implemented tabs:

- **Organization** manages canonical business identity, contact and US mailing information, operational timezone, full logo, and compact mark.
- **Billing** manages the default tax rate and named labor rates.
- **Invoices** displays USD and immutable numbering rules, manages default payment terms, previews document branding, and reports issue readiness.

No generic settings JSON, custom fields, public asset endpoint, theme editor, or organization switcher is included.

## Schema and canonical-data migration

The additive migration `2026_08_09_010000_create_organization_settings_foundation` adds optional identity fields and current-logo pointers to `organizations`, creates versioned `organization_brand_assets`, and adds the immutable `invoices.seller_logo_asset_id` snapshot reference.

Existing organization display names are never changed. Empty new identity fields are backfilled once from matching legacy seller fields in `organization_billing_settings`. Those legacy seller columns remain for rollback compatibility, but application reads and writes now use `organizations`. Tax, labor configuration, currency, and payment-term defaults remain in the billing settings tables.

Invoice drafts snapshot the active organization identity and full-logo asset. Final issue refreshes that snapshot from canonical organization data after validating the profile. Already issued invoices are immutable and continue to reference their original logo version.

## Authorization

| Area | Capability | Seeded default |
| --- | --- | --- |
| Organization profile, timezone, and branding | `organization.settings.manage` | Super Admin only |
| Billing and Invoice configuration | `billing.settings.manage` | Super Admin only; explicit grants supported |

The Settings entry appears when either capability is effective. `/office/settings` redirects to the first authorized tab. Inactive memberships are rejected, explicit grants and denials remain authoritative, and asset/invoice reads are scoped to the server-resolved active organization.

## Timezone behavior

Changing the organization timezone requires an explicit confirmation checkbox. It changes organization-local Today boundaries and future organization-timezone defaults. It does not rewrite stored UTC timestamps, Service Location timezones, Visit timezones, schedules, time entries, closeouts, invoices, or audit history.

## Private logo storage and retention

- Accepted types: PNG, JPEG, and WebP.
- Maximum size: 5 MB.
- Dimensions: 64 through 4096 pixels on each axis.
- SVG, HEIC/HEIF, animated PNG/WebP, invalid content, and MIME-spoofed uploads are rejected.
- Objects are written to `ORGANIZATION_BRANDING_DISK` with opaque UUID keys and are never rendered as paths or public URLs.
- Organization branding endpoints require an active scoped membership. Invoice branding requires invoice presentation authorization.
- Replacement and reset update the active pointer transactionally. Cleanup runs after commit and deletes only assets that are not current organization logos and are not referenced by any invoice.

Fallback order is full logo → static NewDay portal logo, and compact mark → full logo → static NewDay portal logo. Login and global errors always retain the static portal logo.

## Local preservation record

Before the migration, the active SQLite development database was inventoried and copied to:

`storage/app/backups/pre-organization-settings-20260809.sqlite`

SHA-256: `BA0E32EAB82D17C396978762BB09818A72F4A990A370268D78D109F8D4B9D4B1`

The backup was restored to an isolated target and verified across 41 tables. Baseline operational counts were: 1 organization, 1 user, 2 customers, 3 Service Tickets, 6 Visits, 5 closeouts, 3 reviews, 2 billing handoffs, 1 invoice, and 16 migrations. The `.env` checksum before migration was `E29275F09532F4E32DAF79B1A1EEF5EC3D37E2BB298DBEB11CDD2C22644C4E88`.

## Rollback

Rollback removes the invoice logo reference first, then organization pointers, the brand-assets table, and the new organization columns. Stored private logo objects should be retained until the database rollback is confirmed. Restore verification must always target an isolated database; never restore over the active database without explicit owner direction.

## Validation

Coverage includes partial profiles, timezone confirmation, safe audit metadata, capability overrides, inactive memberships, private/scoped logo delivery, spoof/type/dimension rejection, opaque storage, replacement/reset cleanup, canonical invoice snapshots, historical logo retention, full PHPUnit, beta validation, compiled Blade lint, Pint, Composer validation/audit, Vite, Playwright/axe, and migration preservation checks.

Results recorded on 2026-08-09:

- `composer check`: passed; 100 PHPUnit tests and 763 assertions, Pint, 116 compiled Blade files, and the production Vite build.
- `composer audit --locked`: no security vulnerability advisories.
- `composer beta:setup` and `composer beta:validate`: passed; deterministic fixture counts and 8 tests with 50 assertions.
- `npm run test:e2e`: 3 applicable Playwright/axe scenarios passed and 3 opposite-viewport scenarios skipped by design.
- `php artisan beta:benchmark --env=beta --runs=20 --fail-on-budget`: Today p95 8.2 ms/12 queries; Dispatch p95 17.9 ms/10 queries; ticket detail p95 12.1 ms/21 queries; review detail p95 9.6 ms/26 queries; media first byte p95 0.1 ms.
- `git diff --check`: passed.

The workstation has no Docker executable, so `composer phase:update` could not run its Docker startup step. Its non-destructive component commands were run directly: Composer install, additive migrate, idempotent access-control seed, frontend dependency install, and Vite build. Two already-running local Vite processes held native Node modules open, so `npm ci` could not unlink them; `npm install --no-audit --no-fund` restored the same locked dependency set and the production build passed. No portal process was stopped.

After migration, all operational row counts remained unchanged and the `.env` checksum remained identical. The only expected database changes were migration count 16 → 17, the new schema, and the idempotent system capability seed. A live signed-in owner visual check remains the draft-PR manual gate because the available browser session was not authenticated.
