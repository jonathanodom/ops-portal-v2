# OP-API-0 — Repository Assessment

Status: Complete
Date: 2026-08-29
Milestone: OP-API-0 (see `docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md`)

## 1. Purpose

Inventory the current routing, authentication, authorization, and domain
implementations for Customers, Contacts, Service Locations, Service Tickets,
and Projects before writing any `/api/v1` code, and select an implementation
path consistent with `docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md`
and `.cursor/rules/ops-portal-api-development.mdc`.

No application code changed as part of this work package.

## 2. Stack Summary

| Layer | Current state |
| --- | --- |
| Framework | Laravel 13, PHP 8.4 |
| Routing | `routes/web.php` only. `bootstrap/app.php` registers `web`, `commands`, and `health` (`/up`). **No `api` routing group exists.** |
| Auth guard | Single `web` session guard (`config/auth.php`), Eloquent `User` provider. **No token/API guard (e.g. Sanctum) is installed.** |
| Authorization | Laravel Policies (`app/Policies/*`) + a custom capability system (`Capability`, `Role`, `OrganizationMembership::hasCapability()`) |
| Request correlation | Already implemented — `App\Http\Middleware\CorrelateRequest` accepts/generates `X-Request-ID` and echoes it on the response, plus binds it to the log context. Directly reusable for `/api/v1`. |
| Audit | Already implemented — `App\Support\AuditRecorder` + `App\Models\AuditEvent` (organization-scoped, polymorphic `subject`, `actor_id → users.id`, JSON `metadata`, no timestamps other than `occurred_at`). Directly reusable. |
| Multi-org shape | `Organization` / `OrganizationMembership` exist, but the app is operated as a single active production organization today (per `README.md` and `docs/JARVIS...` — single-company v0.1). No SaaS/tenant work is in scope. |

## 3. Authentication and Authorization — Current Implementation

- `AuthenticatedSessionController` issues a standard Laravel session (`config/auth.php` guard `web`, driver `session`).
- `ResolveActiveOrganization` middleware loads the caller's active `OrganizationMembership` onto the request (`$request->attributes->get('membership')`, `'organization'`) — every downstream controller and Policy reads from these request attributes, not from the session directly.
- Authorization is capability-based, not role-based, at the enforcement point:
  - `RequireCapability` middleware: `->middleware('capability:customers.view')`.
  - Policies call `OrganizationMembership::hasCapability($key)`, which resolves capabilities from `roles.capabilities` plus per-membership `capabilityOverrides` (grant/revoke), then caches the resolved map on the instance.
- Relevant existing capability keys (from `database/seeders/AccessControlSeeder.php`):

  | Key | Meaning |
  | --- | --- |
  | `customers.view` | View customers, service locations (contacts are exposed under the same key; there is no separate `contacts.view`) |
  | `customers.manage` | Create/update customers, service locations, contacts |
  | `service_tickets.view` | View tickets/visits |
  | `dispatch.manage` | **Actually gates ticket + visit creation/update** (`ServiceTicketPolicy::create/update`) |
  | `projects.view` | View projects |
  | `projects.manage` | Create/update projects |

  **Note:** ticket write authorization is gated by `dispatch.manage`, not a `service_tickets.manage` key. Any JARVIS scope-to-capability mapping must account for this — it is existing behavior, not something to change.

- There is **no service-account / machine-identity concept today**. Every `User` row is a human login with a password.

## 4. Domain Layer — Customers, Contacts, Locations, Tickets, Projects

All five domains already follow the target service-layer shape described in
the implementation plan (§10), to varying degrees:

| Resource | Model | Controller | Domain/service classes already extracted | Notes |
| --- | --- | --- | --- | --- |
| Customer | `app/Models/Customer.php` | `Office/CustomerController.php` | — (controller does DB work directly) | `scopeForOrganization`, relations to contacts/locations/tickets/projects/opportunities/invoices |
| Contact | `app/Models/Contact.php` | `Office/ContactController.php` | — | `phone_normalized` column already exists for phone matching (plan §10 asks for this) |
| Service Location | `app/Models/ServiceLocation.php` | `Office/ServiceLocationController.php` | — | `formattedAddress()`, `active` flag, `is_primary` |
| Service Ticket | `app/Models/ServiceTicket.php` | `Office/ServiceTicketController.php` | `App\Domain\ServiceTicketCreator`, `App\Domain\ServiceTicketCreationValidator`, `App\Domain\ServiceTicketWorkflow`, `App\Support\ServiceTicketNumber` | **Already exactly the shape the plan wants**: controller → validator → creator (wrapped in `DB::transaction`) → `AuditRecorder`. Ticket numbers are `NDT-ST-YYYY-0123` via `ServiceTicketNumber`. Canonical enums live in `config/service_tickets.php` (`priorities`, `sources`, `purposes`, `billing_dispositions`, `statuses`). |
| Project | `app/Models/Project.php` | `Office/ProjectController.php` | `App\Domain\Projects\Queries\ProjectWorkspaceQuery` | Project has `project_number`, `status`, `service_location_id`, `serviceTickets()` (many-to-many via `project_service_ticket`) |

Key implication for `/api/v1`: **Service Ticket creation already has a
reusable, transactional, audited service (`ServiceTicketCreator`)** that an
API controller can call directly — this satisfies plan §10 ("do not
duplicate business logic") and rule §7 with effectively no new domain code,
only a thin API controller + request DTO + resource transformer.

Customer/Contact/Location/Project controllers currently embed their query
logic directly in the controller rather than a domain service. For **read**
endpoints this is low-risk to call from an API controller directly (same
query shape, different serialization) without an extraction step. If/when
API write endpoints are needed for these resources beyond what's listed in
plan §7/§11 (out of initial scope), that logic should be extracted the same
way `ServiceTicketCreator` was.

## 5. Policies

`app/Policies/{CustomerPolicy,ContactPolicy,ServiceLocationPolicy,ServiceTicketPolicy,ProjectPolicy}.php`
all follow the same pattern: a `ChecksOrganizationCapability` trait resolves
`OrganizationMembership::hasCapability()` for the given `User` + `Organization`/`organization_id`.
These Policies are guard-agnostic — they take a `User` model, not a session.
**This means a token-authenticated `User` (service account) can be authorized
through the exact same Policies with zero changes**, provided the token guard
resolves to a real `User` row with an `OrganizationMembership`.

## 6. Gaps vs. the Implementation Plan

| Plan requirement | Status |
| --- | --- |
| `/api/v1` routing namespace | Missing — must be added in `bootstrap/app.php` + `routes/api.php` |
| Service-account bearer-token authentication | Missing — no token package installed (no Sanctum, no Passport) |
| Scope enforcement per plan §7 (`customers.read`, `tickets.create`, ...) | Missing — existing capability keys don't map 1:1 (see §3 note above); needs an explicit decision (§8) |
| `Idempotency-Key` support for ticket creation | Missing — `ServiceTicketCreator` has no idempotency-key storage today |
| JSON success/error envelope (`{"data":...,"meta":{"request_id":...}}`) | Missing — needs a small shared API response helper/trait |
| `docs/openapi.yaml` | Missing |
| API feature tests (`tests/Feature/Api/...`) | Missing |

## 7. Reusable Infrastructure (do not rebuild)

- `CorrelateRequest` middleware — reuse as-is for `X-Request-ID` on `/api/v1`.
- `AuditRecorder` / `AuditEvent` — reuse as-is; JARVIS writes will call `AuditRecorder::record()` with the service-account `User` as actor, same as human writes.
- `RequireCapability` middleware and all five Policies — reuse as-is once a service-account `User` + `OrganizationMembership` exists.
- `ServiceTicketCreator`, `ServiceTicketCreationValidator`, `ServiceTicketNumber`, `ServiceTicketWorkflow` — call directly from the API ticket controller.
- `config/service_tickets.php` canonical enums — expose these values in OpenAPI verbatim (plan §8.3 explicitly forbids inventing new enums).

## 8. Open Decisions Required Before OP-API-1 (C-002/C-003)

These are architecture-adjacent choices the plan does not fully prescribe.
Per rule §22 (work-package discipline) and rule §1 (no redesign without
instruction), these are presented for a decision rather than assumed:

1. **Token mechanism.** Plan §6 lists "OAuth2-style client credentials or
   equivalent short-lived JWT" as *preferred*, and "long-lived random service
   token, hashed server-side, scoped, revocable" as *acceptable for v0.1*.
   **Recommendation:** add `laravel/sanctum` and use its personal-access-token
   abilities (`->tokenCan('tickets.create')`) as the v0.1 scope mechanism —
   it is hashed-at-rest, revocable, and is the standard, low-risk Laravel
   primitive for exactly this case. This is a new dependency, not a rewrite
   of existing auth.
2. **JARVIS identity as a `User` row.** Recommend creating one dedicated
   `User` (e.g. `jarvis-core@service.newdaytech.net`, `status` distinguishing
   it as a service account) with one `OrganizationMembership` holding a new,
   narrowly-scoped `Role` (e.g. `jarvis_service`) built only from the
   capabilities in plan §7. This lets every existing Policy work unmodified
   (§5) and lets `AuditRecorder` attribute actions to a real actor.
3. **Scope-key mapping.** Recommend introducing new capability keys
   dedicated to the API surface (e.g. `api.customers.read`,
   `api.tickets.create`) rather than reusing UI capability keys like
   `dispatch.manage`, so that API scope grants can be changed independently
   of human-role permissions (rule §10, two-layer authorization). Sanctum
   token abilities would then be named to match 1:1 with plan §7
   (`customers.read`, `tickets.create`, etc.) and the middleware/Policy
   layer checks the *membership* capability as today.
4. **Idempotency-Key storage.** Needs one small table (e.g.
   `idempotency_keys`: `key`, `organization_id`, `actor_id`, `route`,
   `response_status`, `response_body`, `created_at`) or a narrower
   ticket-specific column. Recommend a small reusable table since the plan
   anticipates idempotency on future write endpoints beyond tickets.

No code will be written against these until confirmed, per rule §1.

## 9. Recommended Next Work Package

**OP-API-1** (per plan §15): `/api/v1` foundation + auth.

Scope, pending confirmation of §8 above:
- `routes/api.php` registered via `bootstrap/app.php` (`api:` prefix `api/v1`).
- Install `laravel/sanctum`; migration for personal access tokens.
- One `jarvis_service` `Role` + capability keys seeded (additive migration/seeder, no changes to existing roles).
- One service `User` + `OrganizationMembership` created via an Artisan command (not seeded with a real secret in source).
- Shared JSON envelope helper (success/error shape from plan §5).
- `CorrelateRequest` applied to the `api` middleware group.
- `GET /api/v1/me` (or `/health`) authenticated smoke endpoint returning the resolved scopes — first thing JARVIS can call to prove the boundary works end to end.
- Feature tests: missing token, invalid token, valid token + correct scope, valid token + insufficient scope.

No changes to `resources/views/`, existing controllers, or existing routes are required for OP-API-1.

## 10. Risk Notes

- **Low risk.** This work package is additive: new routes file, new
  dependency, new migration, new seed data, no edits to existing
  controllers, models, Policies, or views.
- The only touch point shared with existing code is the `User` model and
  `AuditRecorder`/`AuditEvent`, both of which are reused unmodified.
- No destructive migration is required for OP-API-1.

## Escalation

None. This is standard Sonnet-tier repository analysis (rule §3); no
irreversible or cross-subsystem decision was made — §8 lists recommendations
only, pending explicit confirmation before implementation begins.
