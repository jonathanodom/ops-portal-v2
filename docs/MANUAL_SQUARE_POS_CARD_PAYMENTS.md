# Manual Square POS Card Payments

## Baseline and scope

Implementation branched from `main` at `5301383883c72168baa40bcec99637b19f7814dc`. This change lets an authorized Office user record a credit- or debit-card payment only after it has succeeded on a physical Square POS terminal. Ops Portal does not initiate, verify, reconcile, or automatically refund that terminal transaction.

The existing `payments.record_manual` and `payments.refund` capabilities, organization scoping, issued-Invoice requirement, balance ceiling, idempotency, receipt creation, and payment-state calculations remain authoritative.

## Tender and provenance

`payment_transactions.method` describes the tender:

- `cash`
- `check`
- `credit_card`
- `debit_card`

The additive nullable `payment_transactions.payment_source` describes who owns the transaction rail:

- `manual` for cash and check
- `square_pos` for a Square terminal transaction recorded after completion
- `hosted_checkout` for an authoritative connected Square or Stripe checkout

The migration backfills provider-null transactions as `manual` and provider-owned transactions as `hosted_checkout`. New application paths always write or inherit the source explicitly. The column remains nullable for portable additive migration and rollback behavior, while application validation constrains all new values.

Manual Square POS records deliberately keep `provider` and `payment_attempt_id` null. In this application, non-null `provider` means Ops Portal owns an authoritative connected-provider transaction that participates in webhooks, reconciliation, and provider refunds. A keyed terminal record does not satisfy that contract.

## Reference and reversal behavior

`manual_reference` remains the safe reference field:

- check number/reference is required for checks;
- Square payment/transaction/receipt reference is optional but strongly encouraged;
- cash reference remains optional.

Manual Square POS corrections use the manual reversal ledger. Recording that reversal does not refund the card in Square; the operator must complete the external Square action separately. Connected Square and Stripe transactions retain their provider refund workflow. Refund and reversal rows inherit the original transaction source.

## PCI boundary

The workflow records only tender, provenance, amount, received time, optional non-sensitive reference, and the existing internal note. It never accepts or stores PAN/card number, CVV/CVC, expiry, PIN, track data, EMV data, Square credentials, or provider payloads.

## Future Finance seam

A future Finance domain can match `payment_source=square_pos`, `manual_reference`, amount, received time, Invoice, and Customer context through a Finance-owned reconciliation relationship. This change adds no Finance model, foreign key, import, bank feed, settlement matching, or accounting integration.

## Validation

Focused coverage verifies canonical tender/source mapping, spoof resistance, null provider/attempt boundaries, cash/check regressions, optional Square references, Invoice balance and payment state, safe receipts and history labels, manual reversal without provider resolution, hosted-checkout provenance, migration backfill, tenant isolation, and safe audit metadata.

Validation completed on the feature branch:

- Full PHPUnit: 360 tests and 2,969 assertions passed.
- Focused Phase 7 payments: 18 tests and 139 assertions passed.
- Payment, Invoice, provider, receipt, and Dashboard A/R regression slice: 53 tests and 454 assertions passed.
- Additive migration: full migrate, rollback, and reapply rehearsal passed on an isolated SQLite database.
- Composer validation and security audit, Pint, 175 compiled Blade files, Vite production build, and `git diff --check` passed.
- Isolated beta setup, validation, and query/response benchmark budgets passed with the expected 250 Customers, 400 Locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, and 500 media records.
- Authenticated Playwright/axe: 22 applicable scenarios passed with 18 expected project skips. The Record Payment dialog also passed focused checks at 390, 768, 1280, 1440, and 1920 pixels with 44px choices and no horizontal overflow.

## Non-goals

No Square Terminal API, transaction lookup, automatic matching, POS webhooks, automatic Square refund, Finance module, bank feed, reconciliation table, fees, disputes, split tender, tips, card capture, Catalog/Inventory change, or Field/customer payment-entry workflow is included.
