# Commercial Operations V1 — Phase 2 Quote and Revision Foundation

## Delivery boundary

Phase 2 extends `feat/commercial-operations-v1-checkpoints-0-3` from the owner-approved Phase 1 head `a75729b43131399064660634b1c8ec124882ca27`. It adds the internal Quote/revision estimating workspace only. It does not publish Proposals, expose public tokens, collect signatures, approve exceptions, convert Projects, or create Invoices.

## Schema and rollback

Additive migration `2026_08_27_030000_create_commercial_quote_foundation.php` adds Customer tax-exemption fields, organization default Systems/Phases, Commercial Documents/Revisions, revision-owned Locations/Systems/Phases/Sections, Catalog-backed lines, editable Package component snapshots, and payment milestones. `down()` removes only these Phase 2 additions. Existing FSM, Projects, Catalog, Billing, Invoice, Payment, file, and signature migrations remain immutable.

Quotes use `document_sequences` with the Organization-local year and locked numbering (`Q-YYYY-NNNN`). Revision display numbers append `-Vn`; sequence values may grow beyond four digits.

## Authorization

| Capability | Super Admin | Dispatcher | Other seeded roles |
| --- | --- | --- | --- |
| `quotes.view` | Yes | Yes | No |
| `quotes.manage` | Yes | Yes | No |
| `quotes.cost_margin.view` | Yes | No | No |

Active membership and explicit capability denials remain authoritative. Every controller lookup, dimension selection, Catalog source, line, component, and revision is scoped to the active Organization. Cross-Organization identifiers return 404.

## Calculation and snapshot rules

- Amounts use integer cents, quantities use thousandths, and rates use basis points.
- Line quantity scaling, half-up discounts/tax, proportional Quote-discount allocation, and stable remainder-cent distribution are deterministic.
- Calculation order is gross sell, line discount, Quote discount allocation, taxable base, tax, and total.
- Customer tax exemption and reference are snapshotted when the Quote is created.
- Product cost preserves Catalog cost amount plus cost-quantity basis. Service costs remain visibly unresolved until Phase 3 supplies approved Service/labor-role estimating defaults.
- Package lines retain one customer transaction line and revision-owned Product/Service component snapshots. Editing those components never changes the canonical Catalog recipe.
- Optional lines are retained but excluded until selected. Allowances are the only intentional non-Catalog line type.
- Margin, markup, profit, and cost remain unresolved when any included cost input is unresolved; no cost is invented.
- Only Draft revisions can mutate. Every write checks `content_version`, recalculates, and replaces the canonical SHA-256 content hash. Locked history is cloned into the next Draft version.
- Existing Catalog changes or deactivation do not mutate snapshotted Quote history.

## Retained local database preservation

Before migration:

- 50 migrations
- Customers 8; Projects 2; Service Tickets 12; Visits 13; Closeouts 11; Invoices 13; payment transactions 3; audit events 177; Opportunities 0
- Verified backup: `storage/app/backups/commercial-phase1-before-phase2-20260827.sqlite`
- SHA-256: `E16E643AA119E513C9F28B149788CA4F70C3537EE32C06BD90E9F751FD6829F3`
- Isolated restore verification: passed, 83 tables with migrations, counts, relationships, and representative workflows matching

After the additive migration and idempotent access-control seed:

- 52 migrations
- Every retained operational count above remained unchanged
- Commercial Documents/Revisions remain 0; six default Systems and six default Phases were added
- `.env` was not replaced and the database was not reset

## Local owner review

Use the retained-data path:

```powershell
composer phase:update
composer dev
```

As Super Admin or Dispatcher:

1. Open `/office/opportunities`, create/open an Opportunity, and create two differently titled Quotes.
2. Confirm each receives an immutable `Q-YYYY-NNNN` number and opens at `V1`.
3. Add Products, Services, Packages, and a priced Allowance. Confirm no arbitrary custom Product/Service line exists.
4. Switch grouping among Location, System, Phase, Category, and type; confirm lines do not duplicate.
5. Add a revision-owned Location/System/Phase, move/copy lines, and use a bulk Location move.
6. Edit quantity, direct sell price, Catalog price, markup/margin modes, line/Quote discounts, taxability, tax rate, and optional inclusion.
7. As Super Admin, confirm internal cost/margin appears; as Dispatcher, confirm it does not. Confirm unresolved Service cost is clearly labeled.
8. Add a Package and tailor its component quantity/waste/visibility. Confirm the Catalog Package recipe is unchanged.
9. Add percentage/fixed payment milestones and a balancing milestone; confirm allocations equal the Quote total including remainder cents.
10. Lock `V1`, confirm it is read-only, clone `V2`, edit `V2`, and confirm `V1` remains unchanged.
11. Review at 390, 768, 1280, 1440, and 1920 pixels for card/table usability, visible focus, 44px controls, and no page-level horizontal overflow.

## Explicit exclusions

No approval requests, publication, PDF/email, customer link, comments, customer response, signature, acceptance, Opportunity automation, Project conversion, Change Orders, deposit Invoice, or Phase 3 Add Catalog Item overlay is included.

Exact final local and GitHub validation results are recorded in PR #52 before the Phase 2 acceptance gate.
