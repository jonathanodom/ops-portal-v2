# Field Visit Workspace V2 Experiment

## Baseline and dependency

The experiment branches from merged `main` at `69d6de36855560236417d88545c2bfb055aa4f5d`. PR D / #46 is merged at `38a8f46a59d3ec929a62cfa9160c706c444d24f1`, and its version-owned acknowledgment signature implementation is the only signature system used by V2. Service Ticket Document Profiles V2 is also preserved in the baseline. The baseline `main` CI run `32882969924` passed core, MySQL backup/restore safety, browser/axe, and aggregate validation.

## Why this is an experiment

The classic Field Visit page remains the canonical default from Today and Customer history. It is fully functional and unchanged except for **Try new Visit workspace**. V2 is available only at:

`GET /field/visits/{visit}/workspace-v2`

V2 always exposes **Switch to classic workspace** for the same Visit. No preference, workspace table, duplicate Visit, duplicate Closeout, or migration exists. The experiment can be removed by deleting its GET route, controller, Blade files, JS module, and CSS while leaving every business record intact.

## Shared records and endpoints

V2 uses the same organization-scoped Visit policy and the existing canonical mutation routes for:

- assigned → En Route → On Site transitions;
- individual timers, corrections, and PR C work-focus switches;
- PR B Work Item creation and disposition;
- Closeout draft optimistic locking and final submission;
- Catalog/custom part proposals;
- private media upload, display, and soft removal;
- PR D signature capture and acknowledgment fallbacks.

The existing media endpoint now returns a backward-compatible safe representation containing only ID, category, caption, authenticated show/remove URLs, message, and current readiness errors. It never returns a disk, storage key, or filesystem path. Draft save and media removal return JSON only when requested; classic redirect behavior remains unchanged.

## Workspace structure

V2 is one server-rendered page with plain-JavaScript enhancement and URL-hash state:

- **Overview:** customer/site, POC actions, access, scope, schedule, crew, and return context.
- **Work:** primary scope, Additional Work Items, handled/current-focus indicators, dispositions, and atomic **Work on this** actions.
- **Time:** active timer, effective PR A intervals, office-correction markers, captured focus, current PR C allocations, and existing correction controls.
- **Evidence:** rapid photo queue plus existing Catalog and custom proposals.
- **Closeout:** shared-draft summary, version history, and the guided finish flow.

Without JavaScript, all server-rendered panels remain in document order. With JavaScript, one accessible tabpanel is visible at a time. Tab switching and opening the wizard create no audit records.

## Rapid photo queue

The selected configured category persists until changed. **Take photo** uses `capture="environment"`, automatically queues its capture, starts upload, resets the camera input, and is immediately reusable. **Choose multiple** queues every supported image under the selected category.

The in-memory queue permits at most two independent XHR uploads. Each row reports queued, uploading/progress, uploaded, offline, or failed. A failed item does not affect a successful item and requires explicit Retry. Successful uploads update the authenticated evidence list and readiness result without navigation or page reload. Remove uses the existing soft-removal workflow through an opt-in JSON response.

Offline files live only in the current page memory. Reconnection does not silently persist them; the technician must choose Retry. There is no service worker, IndexedDB, background upload, or durable offline claim.

## Guided finish flow

**Finish Visit** opens a native full-screen mobile dialog with five steps:

1. Outcome
2. Work summary
3. Evidence & work check
4. Acknowledgment
5. Review & Submit

Outcome selection progressively reveals only relevant existing fields. **Use completed Work Items** and **Use follow-up Work Items** produce deterministic editable text from the Ticket scope and current Work Item records. A nonblank narrative is never replaced without confirmation; no AI or external request is involved.

**Save & continue** calls the canonical draft endpoint with the shared `content_version`. HTTP 409 preserves the latest version and requires explicit review/retry. The Review checklist is rendered and refreshed from `CloseoutReadiness`; client state cannot override submission validation. Fix actions move to the corresponding V2 tab or wizard step. Final submission uses the canonical endpoint and PR D signature/fallback behavior.

## Read-only and revision behavior

Canceled and submitted Visits retain readable Overview, Work, Time, Evidence, and Closeout context while mutation controls are absent. Returned-for-correction Visits use the current draft revision, show inherited evidence separately, retain earlier immutable versions, and require the current PR D acknowledgment.

## Security and auditing

Authentication, active membership, Field experience access, Visit policy, active Organization, tenant-scoped Work Items, MIME/size/count checks, private media routes, Closeout readiness, CSRF, signature validation, and lead/execute-any submission remain server-authoritative. Tabs, badges, selected categories, and checklist state grant no permission. Existing domain mutations retain their current audits; V1/V2 switching, tabs, dialogs, and local queue activity are not audited.

## Performance

The representative focused fixture runs ten warm requests for each workspace:

- V1: 55 queries, 23.4 ms p95.
- V2: 50 queries, 18.6 ms p95.

The focused guard requires V2 to remain within five queries of V1. These local timings are diagnostic rather than a flaky CI wall-clock gate.

## Validation

- Focused V2 routing, tenant, shared draft, media JSON/privacy, Work/Time/Parts interchange, read-only, conflict, and query tests: passing.
- PR A submitted corrections, PR B Work Items, PR C attribution, PR D signatures, and complete Mobile Field regression: 45 tests and 427 assertions passing.
- Full PHPUnit: 421 tests and 3,597 assertions passing in 51.9 seconds. The workstation's default 128 MB CLI limit exhausted late in the first run; the same suite passed with a 512 MB CLI allowance and peaked at 122 MB.
- Composer validation and audit, Pint, compiled Blade rendering, Vite production build, and `git diff --check`: passing.
- Isolated beta fixture: exact expected counts and SQLite integrity passing. Beta hardening: 8 tests and 56 assertions passing.
- Ten-run beta benchmark p95: Dashboard 17.9 ms, Today 10.9 ms, Dispatch 24.0 ms, Projects workspace 14.0 ms, Customer detail 13.3 ms, Project detail 22.9 ms, Ticket detail 17.5 ms, Review detail 17.1 ms, and private-media first byte 0.1 ms. All query ceilings and budgets pass.
- Full Playwright/axe matrix: 28 applicable workflows passed and 24 intentionally project-skipped in 2.9 minutes. The V2 scenario passed at 390, 430, 768, 1280, 1440, and 1920 pixels with no horizontal overflow or serious/critical axe violations.
- GitHub Actions results will be appended to the draft PR after the branch run completes.

## Known limits and rollback

Photo queues do not survive refresh, navigation, browser termination, or device restart. Mutations remain online-only. The experiment adds no new FSM, automatic save-per-keystroke, bulk media model, public media, remote signature, Billing behavior, staff time tracking, or frontend framework.

Rollback in the field is immediate: choose **Switch to classic workspace**. Today continues to open V1 by default and all saved V2 activity is already canonical V1 data.
