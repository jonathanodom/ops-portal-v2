# Office UI Scene Inventory

## Status legend

- **Captured:** real beta route/state captured in Checkpoint 0.
- **Reachable later:** route and behavior exist, but a dedicated state capture is scheduled for the checkpoint that changes it.
- **Fixture gap:** route exists, but the isolated sanitized beta fixture does not contain the required record/history.
- **Not implemented:** the screenshot plan describes behavior that is not in the accepted baseline; it must not be fabricated.
- **Excluded:** outside the approved Office modernization scope.

## Captured baseline scenes

| Family | Captured IDs | Record setup |
|---|---|---|
| Shell/Home | H01 | Beta Super Admin, populated operational dashboard |
| Customers/Locations | C01, C04, C09, C10 | 250 customers, 400 locations |
| Projects | J01, J03 | Two seeded projects with workstreams/tasks/milestones |
| Commercial indexes | O01, P01, A05, A06 | Authentic empty/configuration states |
| FSM | ST01, ST03, D01 | 500 tickets and 1,000 visits |
| Review/Billing | R01, R02, B01, I01, I03 | 200 closeouts, two handoffs, two invoices |
| Catalog/Services | K01–K06 | Authentic empty Catalog/enrollment indexes; Units populated |
| Settings/Admin | A01, A03, A04, A08, A09 | Super Admin configuration and diagnostic states |

Every captured primary index has a D image. H01, C01, J01, O01, ST01, D01, R01, B01, I01, K01, and K02 have the complete R5 viewport set. C04, J03, ST03, and R02 also have the complete R5 set. C10 and I03 have desktop baseline captures.

## Existing behavior to capture during its implementation checkpoint

| Family | IDs | Status |
|---|---|---|
| Shared shell/states | S01, S03, S07–S10, S16–S18, S20 | Reachable later on representative real screens |
| Home/search | H02–H05 | Reachable later; role and search-result setup required |
| Customer forms/states | C02–C03, C05–C08, C11–C12 | Reachable later |
| Project operations | J02, J04–J05, J09–J13 | Reachable later with seeded project records |
| Ticket/Visit operations | ST02, ST04–ST09, D02–D04 | Reachable later with targeted states |
| Closeout/Billing | R03–R06, B02–B03 | Reachable later with targeted evidence and role states |
| Invoice workflows | I04–I14, I16 | Reachable later with targeted invoice/payment states |
| Settings/Admin | A02, A07, A10 | Reachable later; A07 currently lives inside Commercial configuration rather than a standalone primary route |
| Operational documents | X01–X05 | Existing authenticated print routes; final capture belongs to the document/workspace checkpoint |
| Cross-cutting states | E01–E15 | Representative-state capture belongs to hardening |

## Sanitized fixture gaps

| Family | IDs | Missing beta data |
|---|---|---|
| Opportunity details/forms | O04–O10 | Opportunity, stage activity, tasks, attachments, and Quote associations |
| Quote builder | Q01–Q18 | Catalog-backed Quote and revision records |
| Proposal lifecycle/customer experience | P02–P21 | Approval, publication, recipient, customer response, acceptance, and PDF records |
| Conversion/deposits | V01–V09 | Accepted publication, conversion, Project commercial scope, and payment milestones |
| Change Orders | CO01–CO06 | Accepted Project baseline and Change Order revisions |
| Catalog record details/forms | K07–K15 | Catalog Services, Products, Packages, recipes, and enrollments |
| Payments/receipts | I10–I12, I15 | Confirmed/manual payment and receipt records |
| Project accepted scope/plans | J06–J08 | Accepted commercial scope, material plan, and labor budget |
| Proposal media | X06 | Quote/Proposal media fixture |

These scenes must be backed by a deterministic sanitized fixture before design comparison. Checkpoint 0 does not alter beta seeders.

## Baseline features not implemented

| IDs | Finding |
|---|---|
| S02 | No collapsed desktop sidebar convention exists. |
| S04–S06 | `/office/search` exists, but no persistent shell command-search overlay exists. |
| S11 | No generalized saved/custom views behavior exists. |
| S12 | No generalized table bulk-selection/action bar exists. |
| S13 | No generalized column chooser exists; sorting is page-specific. |
| S14–S15, I02 | No generalized persistent list/detail split-pane convention exists. |
| S19, I09 | No shared right-side drawer convention exists. |

These are candidates for explicit implementation only where the modernization checkpoint authorizes the behavior. They are not baseline defects and will not be simulated for screenshot approval.

## Excluded reservation

Future Inventory and Purchasing scenes remain excluded. There are no approved routes or workflows to capture, and the modernization work must not introduce them.
