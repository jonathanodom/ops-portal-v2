# Phase 8.6 Connected Payments & Billing Workspace

## Delivery record

- Baseline: `1d205b9281d54ba689df9c2f97e04a317758d1aa`
- Branch: `codex/phase-8-6-connected-payments-billing-workspace`
- Final implementation before the validation commit: `259da928b2af309ff9c6f4d2cbf6d3948f3d7d9f`
- Scope: Checkpoints 1–9 plus this Checkpoint 10 acceptance gate
- Production/live transactions: none authorized or attempted
- Production deployment: not included

Phase 8.6 replaces normal organization-level processor-secret entry with provider-hosted account authorization, adds one organization default electronic processor, routes provider webhooks canonically, restructures the Invoice workspace, separates the Billing queue from the Invoice ledger, and applies a deliberately small Field usability pass. Existing cash/check collection, immutable ledger rules, provider reconciliation, Billing Handoff recovery, Invoice immutability, Visit provenance, and organization isolation remain authoritative.

## Schema additions

All Phase 8.6 migrations are additive and reversible.

### `2026_08_13_010000_create_connected_payment_foundation`

- Adds hosted-connection metadata to `payment_provider_configurations`: connection method, connected account name, encrypted OAuth access/refresh tokens, expiry, connection/refreshed/disconnected timestamps, and connected actor.
- Adds `organization_billing_settings.default_payment_provider`.
- Adds `payment_provider_authorization_states` with organization, provider, actor, hashed state, encrypted PKCE verifier, environment, safe return path, expiry, and single-use timestamp.

### `2026_08_13_020000_add_square_location_metadata`

- Adds Square payment-location name and the discovered active-location projection.

### `2026_08_13_030000_add_stripe_connection_metadata`

- Adds the connected Stripe account's payment-readiness state.

Legacy credential columns remain for historical rollback compatibility. Normal hosted-connection screens do not display or accept per-organization API secrets.

## Server-side environment configuration

Application credentials belong only in the application environment or secret manager. They must never be entered by ordinary organization users.

### Square

- `SQUARE_SANDBOX_APPLICATION_ID`
- `SQUARE_SANDBOX_APPLICATION_SECRET`
- `SQUARE_SANDBOX_WEBHOOK_SIGNATURE_KEY`
- `SQUARE_PRODUCTION_APPLICATION_ID`
- `SQUARE_PRODUCTION_APPLICATION_SECRET`
- `SQUARE_PRODUCTION_WEBHOOK_SIGNATURE_KEY`

### Stripe Connect

- `STRIPE_TEST_CONNECT_CLIENT_ID`
- `STRIPE_TEST_PLATFORM_SECRET`
- `STRIPE_TEST_CONNECT_WEBHOOK_SECRET`
- `STRIPE_LIVE_CONNECT_CLIENT_ID`
- `STRIPE_LIVE_PLATFORM_SECRET`
- `STRIPE_LIVE_CONNECT_WEBHOOK_SECRET`

### Shared safety flags

- `PAYMENTS_LIVE_ENABLED=false` remains the default.
- `PAYMENTS_FAKE_PROVIDER=false` outside deterministic tests.
- Live/production account connection additionally requires the production application environment and HTTPS application URL.

## Provider URLs

For production `APP_URL=https://portal.newdaytech.net`, register:

- Square OAuth callback: `https://portal.newdaytech.net/office/settings/billing/payments/square/callback`
- Stripe Connect callback: `https://portal.newdaytech.net/office/settings/billing/payments/stripe/callback`
- Square canonical webhook: `https://portal.newdaytech.net/webhooks/payments/square`
- Stripe canonical webhook: `https://portal.newdaytech.net/webhooks/payments/stripe`

The legacy opaque-configuration webhook route remains available for historical attempts but new provider setup should use the canonical provider URL.

## Payment connection and locking rules

- Only an active membership with `payments.settings.manage` can connect, refresh, select a Square location, enable, set the default, disable, or disconnect a provider.
- OAuth state is random, hashed, short-lived, actor/organization/provider scoped, and single-use.
- Connected tokens are encrypted at rest and never rendered, logged, or placed in audit metadata.
- A provider must be connected, payment-ready, and enabled before it can become the organization default.
- Ordinary collection resolves the organization default, then the sole ready provider. The normal Invoice editor contains no processor selector.
- A Super Admin may use the guarded payment overlay override when another processor is ready.
- Open, processing, or unknown attempts prevent switching. Failed, expired, or canceled attempts do not permanently lock the provider.
- The first successful electronic payment establishes the financial provider lock.
- Cash/check payments do not establish or alter the electronic-provider lock.
- Canonical webhooks verify the raw-body signature, resolve the connected merchant/account, reject unknown identities, and converge through the existing idempotent payment workflow.

## Billing workspace

- Invoice lifecycle actions remain in a compact sticky command bar.
- Billing identity/terms are edited in a focused native dialog.
- Invoice Lines are the central compact desktop table/mobile card workspace.
- Line and manual-item editors, Record Payment, Pay Securely, and Payment History use accessible responsive overlays.
- Billing now provides separate **Queue** and **Invoices** views. Invoices originate only from approved Billing Handoffs.
- The Invoice ledger provides organization-scoped search, status/payment/balance/date filters, sorting, pagination, and direct Invoice navigation.
- No arbitrary manual Invoice creation or accounting dashboard was introduced.

## Automated validation

### Application and focused financial tests

- Complete PHPUnit regression: 200 tests, 1,688 assertions — passed.
- Connected-payment, Square, Stripe, webhook/provider, payment, and Invoice suites: 58 tests, 483 assertions — passed.
- No automated test performed a real or live payment.

### Static/build checks

- Composer validation: passed.
- Composer security audit: no vulnerability advisories.
- Pint: passed.
- Compiled Blade cache and PHP syntax lint: passed.
- Vite production build: passed.
- `git diff --check`: passed.

### Beta, recovery, and performance

- All 29 migrations applied successfully to the isolated Beta database.
- Deterministic fixture validation passed: 1 Organization, 5 Users, 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, 500 media metadata records, 3 scenarios, and 5 role assignments.
- SQLite integrity: passed.
- Active development backup SHA-256: `6159FD5232C6119B42221CD60648A05A23C448EA08DA3282693476A3B323E851`.
- Isolated restore verification passed for migrations, all 58 table counts, key relationships, and representative workflows. The active database was never overwritten.
- Warm benchmark results: Today p95 10.2 ms / 18 queries; Dispatch p95 12.9 ms / 16 queries; Ticket detail p95 15.1 ms / 21 queries; Review detail p95 12.7 ms / 26 queries; authorized media first byte p95 0.1 ms.

### Browser/accessibility

- Complete Playwright matrix: 8 applicable tests passed; 8 opposite-device projects intentionally skipped by guards.
- Covered desktop and mobile Billing, Invoice, payment overlays/history, Catalog, dispatch/review/health, Customer/Location, Field visit, offline handling, and customer-safe presentation.
- Serious/critical axe violations: none.
- Responsive overflow and minimum touch-target assertions: passed.

The current workstation does not have Docker installed, so the final local gate used the supported isolated SQLite development/Beta workflow. GitHub Actions repeats migrations, seeders, tests, backup/restore, Beta fixtures/benchmarks, and Playwright against MySQL 8.4 before approval.

## Manual Square Sandbox acceptance

Do not use production credentials or a real payment method.

1. Configure the Square Sandbox application ID, secret, webhook signature key, callback URL, and canonical webhook URL in the provider dashboard and server environment.
2. Sign in as Super Admin, open **Settings → Billing**, and choose **Connect Square Sandbox**.
3. Complete Square-hosted authorization using the intended Sandbox seller. Confirm merchant identity and discovered locations render without any secret input.
4. Select the intended Sandbox payment location, enable Square, and make it the organization default.
5. Open an issued positive-balance Invoice. Confirm **Pay Securely** identifies Square without an Invoice processor selector.
6. Create a partial Sandbox Payment Link, open it, and complete it using Square's documented Sandbox test payment method.
7. Confirm the signed canonical webhook creates one payment transaction and one receipt, updates balance/payment state, and duplicate delivery creates neither duplicate.
8. Exercise payment-link refresh, failed/expired attempt switching, cash/check coexistence, partial refund, and receipt HTML/PDF.
9. Confirm an unresolved attempt blocks switching, while a failed/expired/canceled attempt does not; confirm the first successful electronic payment locks Square.
10. Disable Square and verify new checkouts stop while signed historical webhooks remain reconcilable. Confirm disconnect requires typed confirmation and remote revocation before local clearing.

Record the tested Sandbox merchant, location, Invoice number, amount, webhook event ID, receipt, and observed result without copying tokens, hosted URLs, raw payloads, card data, or customer-sensitive values into the PR.

## Manual Stripe test-account acceptance

Do not use live mode or a real payment method.

1. Configure the Stripe Connect test client ID, platform secret, Connect webhook signing secret, callback URL, and canonical webhook URL in the provider dashboard and server environment.
2. Sign in as Super Admin, open **Settings → Billing**, and choose **Connect Stripe Test**.
3. Complete Stripe-hosted authorization using the intended test connected account. Confirm account identity and payment readiness render without any organization secret-key entry.
4. Enable Stripe and make it the organization default.
5. Open an issued positive-balance Invoice. Confirm **Pay Securely** identifies Stripe without an Invoice processor selector.
6. Create a partial Checkout Session and complete it with Stripe's documented test card.
7. Confirm the signed Connect webhook routes by connected account, creates one transaction/receipt, updates balance/payment state, and duplicate/out-of-order delivery remains idempotent.
8. Exercise failed/expired checkout switching, cash/check coexistence, partial refund, reconciliation refresh, public receipt, and private receipt PDF.
9. Confirm unresolved activity blocks switching, successful electronic payment locks Stripe, and refund history never rewrites the issued Invoice.
10. Disable and disconnect Stripe. Confirm remote deauthorization succeeds before local connection data is cleared.

Record the test account, Invoice number, amount, safe provider reference, webhook event ID, receipt, and observed result without storing platform/connected-account secrets, hosted URLs, raw payloads, or card data.

## Approval gate and exclusions

Phase 8.6 remains a draft until:

1. GitHub Actions is green on the final branch.
2. Jonathan completes and records the Square Sandbox and Stripe test-account scenarios above.
3. Provider callbacks/webhooks are registered in the intended non-production provider applications.
4. Billing Queue, Invoice ledger, Invoice editor, payment overlays, receipt projections, and Field workspace are manually confirmed understandable at desktop and phone widths.

Phase 9 Document Management, Knowledge, Customer Portal, AI, proposals/estimates, projects/installations, inventory/purchasing, accounting synchronization, automatic recurring billing/charging, saved-card vault, Square Terminal, production deployment, and live charges remain excluded.
