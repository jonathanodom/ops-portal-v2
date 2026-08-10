# Phase 7 Payments, Receipts, and Payment Completion

## Scope and safety boundary

Phase 7 adds Square Payment Links, Stripe Checkout Sessions, partial electronic collection, cash/check recording, refunds and reversals, derived invoice payment state, and customer-safe receipts. No live transaction, embedded card form, saved card, ACH, BNPL, terminal, email/SMS delivery, accounting synchronization, chargeback workflow, recurring billing, deployment, or production cutover is included.

Local and CI operation is test-only. Live enablement requires all of: `APP_ENV=production`, an HTTPS `APP_URL`, `PAYMENTS_LIVE_ENABLED=true`, a successful provider connection test, and explicit Super Admin confirmation.

## Additive schema

Migration `2026_08_09_020000_create_payment_completion_tables.php` adds:

- `payment_provider_configurations`: one Square and one Stripe configuration per organization, encrypted API/webhook secrets, opaque webhook ID, safe credential fingerprint, readiness, detected account, and connection-test attribution.
- `payment_attempts`: hosted-checkout lifecycle, encrypted hosted URL, hashed return token, provider identifiers, amount, expiration, idempotency, and safe failure code.
- `payment_transactions`: append-only successful/pending payment, refund, and manual-reversal ledger rows with immutable lineage and integer cents.
- `payment_webhook_events`: unique provider event receipt, payload hash, safe processing status, and no raw payload.
- `payment_receipts`: one receipt per successful payment/refund/reversal, hashed public token, private PDF state, and opaque object key.
- Invoice provider preference, permanent electronic-provider lock, and lock timestamp.

Rollback removes Phase 7 data and invoice provider fields. It is not an operational recovery procedure. Restore the verified pre-Phase-7 backup to a new database and validate it before any approved environment switch.

## Provider configuration and rotation

Provider cards live under **Settings → Billing**. Super Admin enters, rotates, tests, enables, disables, or clears credentials with `payments.settings.manage`. Billing sees readiness, environment, safe fingerprint, account ID, last test, and webhook URL but never credential inputs.

- Square requires environment, access token, location ID, and webhook signature key.
- Stripe requires environment, secret key, and webhook signing secret.
- Blank secret inputs preserve current encrypted values.
- Rotating an API secret disables the provider and requires a new successful connection test.
- Clearing requires the provider to be disabled, no open/processing/unknown attempts, and typed `CLEAR SQUARE` or `CLEAR STRIPE` confirmation.
- Disabling stops new checkout creation but keeps the opaque authenticated webhook route available for historical attempts.

Secrets are Laravel-encrypted at rest and hidden from model serialization. They are never returned to forms or included in audit/incident metadata. Hosted checkout URLs are also encrypted and appear only to authorized collectors.

## Capability matrix

| Capability | Super Admin | Billing | Reviewer | Dispatcher / Technician |
| --- | --- | --- | --- | --- |
| `payments.view` | Yes | Yes | Invoice paid/unpaid summary through `invoices.view` | No |
| `payments.collect` | Yes | Yes | No | No |
| `payments.record_manual` | Yes | Yes | No | No |
| `payments.manage_links` | Yes | Yes | No | No |
| `payments.refund` | Yes | No | No | No |
| `payments.settings.manage` | Yes | No | No | No |

Explicit active-membership grants and denials remain authoritative. All settings, invoices, attempts, transactions, receipts, and private documents are organization-scoped. Field users see **Open invoice / collect payment** only when they independently hold both invoice-presentation and payment-collection capability.

## Provider lock and collection rules

- A positive draft must select an enabled, connection-tested Square or Stripe configuration before issue. A zero-dollar invoice needs no provider.
- The preference can change after issue until the first electronic attempt.
- The first attempt permanently sets `electronic_payment_provider`, including when it later fails or expires.
- Only one open/processing/unknown electronic attempt may exist per invoice. Invoice row locking and unique idempotency keys make concurrent retries converge.
- An electronic amount must be positive and no greater than the current balance.
- Browser redirects never create a payment. Only a verified webhook or authoritative provider retrieval may create the immutable payment transaction.
- Ambiguous provider failures stay `unknown`; staff must reconcile or expire the existing attempt instead of retrying a possible charge.
- Cash/check may coexist with a terminal electronic attempt, but manual payment is blocked while an attempt remains open. Checks require a reference.
- A successful payment blocks Phase 6 void/reissue. Corrections use Super Admin refund/reversal history.

## Ledger math

All amounts are integer cents. Invoice state and balance are derived, not manually set:

`balance = invoice total - successful payments + successful refunds/reversals`

States are `unpaid`, `partially_paid`, `paid`, `partially_refunded`, `refunded`, and `overpaid`. Refundable amount is the original successful payment less pending and successful child refunds/reversals. Provider refunds remain pending until authoritative success. Source payment rows are never edited or deleted.

## Webhooks, reconciliation, and incidents

Public webhook routes contain an opaque configuration UUID. Square HMAC and Stripe signature verification run against the raw body before parsing. Event IDs are unique per configuration; only payload hashes and safe event metadata are retained. Duplicate, invalid, unmatched, stuck, failed, overpaid, missing-receipt, receipt-PDF, and balance warnings feed operational incidents without credentials, hosted URLs, payloads, contact data, or reason text.

Run `php artisan payments:reconcile --organization=<id>` to retrieve authoritative state for open attempts. Run `php artisan ops:health-scan` to check payment and existing FSM invariants.

## Receipts and private PDFs

Exactly one receipt record is created after each successful payment/refund/reversal. PDF generation runs after commit and stores an immutable branded PDF under an opaque private-disk key. Staff may retry a failed render.

Public receipt links use a 64-character random token stored only as SHA-256. Creating or rotating a link reveals it once. Public HTML/PDF responses use `no-store`, `noindex`, and restrictive referrer headers and include only organization branding, invoice number, amount, method category, timestamp, safe processor reference, invoice total, and remaining balance.

## Local preservation and validation

Before migration, the active Phase 6/settings SQLite database contained 1 organization, 1 user, 2 customers, 3 Service Tickets, 6 Visits, 5 closeouts, 3 reviews, 2 billing handoffs, 1 invoice, and 17 migrations. A recoverable backup was created at `storage/app/backups/pre-phase7-payments-20260809.sqlite` with SHA-256 `F60615F9435867F52839C5B4281C04599FA4ACD30771DB1413DC6A6F2CC7F17D`. Isolated restore verification passed across 42 tables and representative workflow relationships. The `.env` SHA-256 remained `E29275F09532F4E32DAF79B1A1EEF5EC3D37E2BB298DBEB11CDD2C22644C4E88`.

Validation recorded before PR handoff:

- Full PHPUnit suite: 107 passed with 806 assertions.
- Phase 7 provider, webhook, ledger, receipt, capability, and organization-isolation coverage: 7 passed with 43 assertions as part of the full suite.
- Official Square and Stripe raw-body signature fixtures passed and rejected tampered signatures.
- Authenticated Playwright Chromium/axe run: passed with no failed tests across the desktop and 390×844 mobile projects.
- Compiled Blade PHP syntax: passed for 119 generated files.
- Composer validation: passed; Composer audit reported no security advisories.
- Pint check, Vite production build, and `git diff --check`: passed.
- Deterministic beta fixture validation: passed at 1 organization, 5 users, 250 customers, 400 locations, 500 tickets, 1,000 visits, 200 closeouts, 500 media records, and all 3 scenarios; beta hardening tests passed 8 tests with 50 assertions.
- Local ten-run warm benchmark: Today p95 9.4 ms / 12 queries; Dispatch p95 25.4 ms / 10 queries; ticket detail p95 15.0 ms / 21 queries; review detail p95 15.9 ms / 26 queries; media first byte p95 0.1 ms. All configured local budgets passed.
- Docker remains unavailable on this workstation, so the MySQL 8.4 validation matrix is delegated to CI; no production or live-provider transaction was run locally.

After the additive migration and capability seed, all operational row counts remained unchanged: 1 organization, 1 user, 2 customers, 3 Service Tickets, 6 Visits, 5 closeouts, 3 reviews, 2 billing handoffs, and 1 invoice. The migration count increased from 17 to 18, all four new payment tables remained empty, and the `.env` checksum was unchanged.

The local database is migrated only with the additive migration and idempotent `AccessControlSeeder`. `DatabaseSeeder` never creates provider credentials, demo payments, or receipts.
