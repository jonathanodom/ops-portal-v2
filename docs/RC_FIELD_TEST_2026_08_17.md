# RC Field Test Corrections — 2026-08-17

## Package A baseline

- Branch: `fix/rc-field-test-2026-08-17`
- Originating `main`: `ba7f3c6fd29c06defc1a46d8b2be16bee07c949d`
- Baseline PHPUnit: 308 tests, 2,519 assertions
- GitHub baseline: `main` workflow run `32089008636` passed
- PR #26 remains an unmerged draft and was not imported into this work

## Reproduction matrix

| Report | Result | Resolution |
| --- | --- | --- |
| RC-UX-010 — Office manual-closeout photo parity | Partially reproduced | The Office workflow already used the canonical private `VisitMedia` subsystem, but exposed only a generic picker. It now offers explicit **Take photo** and **Choose from gallery or files** controls with the same source-selection behavior as Field. |
| RC-BUG-012 — Service Tickets navigation opens the wrong page | Not reproduced | Desktop and mobile navigation both target the Service Ticket directory. Regression coverage now verifies the route and page heading; no speculative routing change was made. |
| RC-UX-013 — closeout requirements are not shown at their fields | Reproduced | Every server-owned `CloseoutReadiness` error is now rendered at its exact form control with visible invalid styling, an inline alert, ARIA linkage, and an actionable **Fix** item that closes the review dialog, scrolls, and focuses the field. |
| RC-BUG-014 — Open Tickets appears empty | Reported deep link not reproduced; empty-state UX reproduced | The organization-scoped `status=open` directory returned the beta open tickets correctly. Filtered and unfiltered empty states are now distinct and filtered results offer a clear-filters action. |

No schema, authorization, organization-scoping, private-storage, closeout, or ticket lifecycle rule changed in Package A.

## Automated validation

- Focused PHPUnit: 25 tests, 247 assertions — passed
- Full PHPUnit (SQLite local parity run): 313 tests, 2,567 assertions — passed in 121.17 seconds
- Composer validation: passed
- Composer security audit: no advisories
- Pint: passed
- Compiled Blade lint: 170 files passed
- Vite production build: passed
- Beta fixture: exact counts and SQLite integrity passed
- Beta benchmark (10 runs): Dashboard 10.0 ms/14 queries; Today 10.0 ms/9; Dispatch 9.6 ms/10; Projects 15.6 ms/16; Project detail 18.8 ms/24; Ticket detail 13.1 ms/22; Review detail 14.9 ms/28; media first byte 0.0 ms
- Playwright/axe: 15 passed, 11 intentionally project-skipped; no serious or critical axe violations
- `git diff --check`: passed

MySQL 8.4 migration and full-suite parity remains enforced by the draft PR workflow.

## Review screenshots

- [Field closeout required fields](ui-review/field-test-2026-08-17/package-a/field-closeout-required-fields-390x844.png)
- [Field photo source selection](ui-review/field-test-2026-08-17/package-a/field-photo-source-390x844.png)
- [Office manual-closeout photo sources](ui-review/field-test-2026-08-17/package-a/office-manual-closeout-photos-1440x900.png)
- [Service Ticket directory navigation](ui-review/field-test-2026-08-17/package-a/office-ticket-directory-1440x900.png)
- [Open Service Tickets filter](ui-review/field-test-2026-08-17/package-a/office-open-tickets-1440x900.png)

## Owner retest notes

- Verify the Office manual-closeout camera and gallery/file actions on the production-target phone/browser combination.
- If the navigation or Open Tickets report still occurs in the deployed environment, capture the exact URL and active filters. Current `main` behavior and the isolated beta fixture do not reproduce either failure.
- Package B (Service Ticket files) is intentionally independent and will use a dedicated Ticket-owned private-file model rather than `VisitMedia`.
