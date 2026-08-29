IMPLEMENTATION BRIEF
# Ops Portal API Implementation Plan
> Codex execution plan for creating the controlled API boundary required by JARVIS
| VERSION 0.1 | PRIMARY EXECUTION ENVIRONMENT: CODEX / OPS PORTAL REPOSITORY |
| --- | --- |


### STATUS  AUTHORITATIVE BUILD PLAN
This document is intended to be followed by the coding agent as persistent project instruction. When implementation details conflict with this plan, stop and create an architecture decision record rather than silently changing the system design.
| Companion document | JARVIS Intelligent Operations Platform Architecture v0.1 |
| --- | --- |
| Owner | NewDay Tech |
| Build approach | Parallel development with fixed API contract |
| Long-term direction | Production-first; white-label SaaS remains a future option, not current scope |


## 1. Mission and Outcome
Build a versioned, authenticated API layer over the existing Ops Portal business logic so JARVIS can safely read and write approved operational data without direct database access. The existing Ops Portal remains the system of record and its current production UI must continue to function throughout the refactor.
> **Primary success condition — JARVIS can search a customer, retrieve that customer's open service tickets, and create/update a service ticket through /api/v1 using a scoped service identity. No direct JARVIS database credentials exist.**

## 2. Scope Boundaries
| IN SCOPE | OUT OF SCOPE |
| --- | --- |
| Versioned REST/JSON API under /api/v1 | Multi-tenant/SaaS conversion |
| Service-account authentication and scoped authorization | Replacement of the existing Ops Portal UI |
| Customer/contact/location/ticket/project read endpoints | Direct JARVIS database access |
| Ticket create/update endpoints with idempotency | Broad accounting or destructive administration APIs |
| OpenAPI specification, automated tests, audit events | White-label branding or subscription billing |
| Webhook framework after core REST contract is stable | Rewriting working business modules without API need |


## 3. Non-Negotiable Architecture Rules
1. Inspect the current repository, route structure, authentication implementation, database layer, and ticket/customer services before modifying code.
1. Do not create a second source of business logic for the API. Extract/reuse the same service/domain logic used by the portal where practical.
1. Do not expose raw tables or generic CRUD endpoints. Expose business resources and business operations.
1. Do not permit JARVIS to connect to the Ops Portal database directly.
1. All write operations must pass authentication, scope authorization, validation, and audit logging.
1. All API changes must be backwards compatible within /api/v1 unless explicitly versioned.
1. Do not add tenant_id or SaaS behavior during this phase. NewDay remains the single production organization.
1. Keep secrets out of source control. Tokens must be scoped, revocable, and rotatable.
1. Maintain an OpenAPI 3.x contract that is treated as the source of truth for the JARVIS client.
1. When uncertain about an existing business rule, preserve current portal behavior rather than inventing a new rule.

## 4. Target API Boundary
```text
Ops Portal UI ───────┐
                     ▼
              Domain / Service Layer
                     │
       ┌─────────────┴─────────────┐
       ▼                           ▼
Existing UI Controllers        /api/v1
                                   │
                                   ▼
                            Auth + Scopes
                                   │
                                   ▼
                              JARVIS Core

Database remains behind the service/domain layer.
```

## 5. Shared Integration Contract v0.1
The following contract is duplicated in the Cursor plan. Do not change paths, envelope shape, authentication semantics, or required fields without updating the OpenAPI document and notifying the JARVIS integration layer.
| ITEM | CONTRACT |
| --- | --- |
| Base path | /api/v1 |
| Transport | HTTPS only outside local development |
| Content type | application/json |
| Authentication | Authorization: Bearer <service-token-or-access-token> |
| Request correlation | Accept X-Request-ID; generate one when absent and return it |
| Write idempotency | POST writes accept Idempotency-Key; duplicate key returns original result |
| Success envelope | {"data": ..., "meta": {"request_id": "..."}} |
| Error envelope | {"error": {"code":"...","message":"...","details":...}, "meta":{"request_id":"..."}} |
| Dates | ISO-8601 timestamps in UTC; preserve local timezone as separate metadata when needed |
| IDs | Opaque stable identifiers; clients must not infer table structure from IDs |


## 6. Authentication and Service Identity
JARVIS must authenticate as a dedicated service identity. The external contract remains a Bearer token so the internal implementation can evolve without changing the JARVIS client.
| PRIORITY | IMPLEMENTATION |
| --- | --- |
| Preferred | OAuth2-style client credentials or an equivalent short-lived JWT/access-token flow using the existing Ops Portal auth stack. |
| Acceptable v0.1 | Long-lived random service token stored hashed server-side, scoped to a service account, revocable, expirable, and rotatable. |
| Forbidden | Shared administrator password, user-session cookie reuse, static secret embedded in JARVIS source, or database credentials. |


```text
Authorization: Bearer <token>
X-Request-ID: <uuid>
Idempotency-Key: <uuid>       # required for create operations from JARVIS
```

## 7. Initial JARVIS Scopes
| SCOPE | PURPOSE | V0.1 |
| --- | --- | --- |
| customers.read | Search/read customer records | Required |
| contacts.read | Resolve caller/message/email contacts | Required |
| locations.read | Resolve customer sites | Required |
| tickets.read | Read ticket list/details | Required |
| tickets.create | Create service tickets | Required |
| tickets.update | Update permitted ticket fields | Required |
| projects.read | Read customer/project context | Required |
| equipment.read | Read installed equipment/assets | Later v0.1 |
| communications.create | Append normalized communication records | Later v0.1 |
| users.manage / roles.manage | Administrative privilege | Forbidden |


## 8. API Resource Contract
### 8.1 Customer search and details
```text
GET /api/v1/customers/search?q=T%26S&limit=20
GET /api/v1/customers/{customer_id}

CustomerSummary
{
  "id": "cust_xxx",
  "name": "T&S Manufacturing",
  "status": "active",
  "primary_phone": "...",
  "primary_email": "..."
}
```
### 8.2 Contacts and locations
```text
GET /api/v1/contacts/search?q=<name|phone|email>&limit=20
GET /api/v1/customers/{customer_id}/locations

ContactSummary
{
  "id": "contact_xxx",
  "customer_id": "cust_xxx",
  "name": "...",
  "phones": ["..."],
  "emails": ["..."]
}

LocationSummary
{
  "id": "loc_xxx",
  "customer_id": "cust_xxx",
  "name": "Graham",
  "address": { ... },
  "timezone": "America/Chicago"
}
```
### 8.3 Tickets
```text
GET   /api/v1/tickets?customer_id=<id>&status=open&limit=50
GET   /api/v1/tickets/{ticket_id}
POST  /api/v1/tickets
PATCH /api/v1/tickets/{ticket_id}

POST /tickets minimum body
{
  "customer_id": "cust_xxx",
  "location_id": "loc_xxx",        // optional
  "contact_id": "contact_xxx",    // optional
  "title": "Front office phones rebooting",
  "description": "Customer reports ...",
  "priority": "normal",            // use Ops Portal canonical values
  "source": "jarvis"
}

PATCH /tickets/{id}
{
  "description_append": "Additional caller detail ...",
  "priority": "high"
}
```
Do not invent ticket status/priority enums. Expose the canonical values already used by Ops Portal and document them in OpenAPI. Prefer purpose-built patch fields such as description_append when overwriting an entire record would be unsafe.
### 8.4 Projects
```text
GET /api/v1/customers/{customer_id}/projects?status=active
GET /api/v1/projects/{project_id}
```

## 9. Standard HTTP Behavior
| STATUS | USE |
| --- | --- |
| 200 | Successful read/update |
| 201 | Created |
| 400 | Validation error |
| 401 | Missing/invalid authentication |
| 403 | Authenticated but scope/role denied |
| 404 | Resource not found |
| 409 | Conflict or non-replayable idempotency conflict |
| 422 | Business rule rejected |
| 429 | Rate limit |
| 500 | Unexpected server error; never expose stack trace |


## 10. Service-Layer Refactor Strategy
- Identify existing customer/ticket/project queries and business rules currently embedded in page controllers or route handlers.
- Extract only the logic required by API endpoints into reusable services/repositories while keeping existing UI behavior intact.
- Controllers/API handlers validate and authorize; services execute business operations; repositories/data access isolate persistence concerns.
- Use database transactions for write operations that span more than one record.
- Normalize phone/email matching in one service so JARVIS entity resolution receives consistent results.
- Do not undertake unrelated modernization while extracting these services.

## 11. Audit Logging
Every API write performed by JARVIS must create an audit event. Reads should at minimum retain request-level logs and security-relevant access; sensitive/high-value reads may also use the audit store.
```text
AuditEvent
{
  "request_id": "...",
  "actor_type": "service_account",
  "actor_id": "jarvis-core",
  "scope": "tickets.create",
  "action": "ticket.create",
  "resource_type": "ticket",
  "resource_id": "NDT-ST-...",
  "result": "success",
  "timestamp": "..."
}
```

## 12. Webhook Framework (After REST Core)
| EVENT | PAYLOAD MINIMUM |
| --- | --- |
| ticket.created | event_id, occurred_at, ticket_id, customer_id |
| ticket.updated | event_id, occurred_at, ticket_id, changed_fields |
| customer.updated | event_id, occurred_at, customer_id, changed_fields |
| project.updated | event_id, occurred_at, project_id, changed_fields |
| appointment.changed | Future: event_id, appointment_id, changed_fields |

Sign outbound webhook bodies with an HMAC secret. Include event_id for deduplication. Delivery must be retryable with bounded exponential backoff. Webhook failure must never block the underlying Ops Portal transaction.

## 13. OpenAPI Is the Shared Source of Truth
```text
ops-portal/
  docs/
    openapi.yaml
    API-JARVIS.md
  tests/
    api/
```
- OpenAPI 3.x must describe authentication, schemas, required/optional fields, examples, errors, and scopes.
- Commit the specification with endpoint changes in the same pull request.
- Where practical, validate responses against the schema in automated tests.
- Cursor/JARVIS should generate or type-check its client from this contract rather than manually duplicating DTOs.

## 14. Automated Test Requirements
| TEST CLASS | MINIMUM COVERAGE |
| --- | --- |
| Authentication | missing token, invalid token, expired/revoked token, valid service identity |
| Authorization | each endpoint permits intended scope and rejects insufficient scopes |
| Validation | required fields, malformed IDs, invalid canonical values |
| Customer search | name, phone/email where supported, no-match, limit behavior |
| Tickets | list/detail/create/update, business validation, idempotent create |
| Audit | successful and rejected writes generate expected records |
| Regression | existing Ops Portal user workflow remains functional |


## 15. Implementation Milestones
| MILESTONE | DELIVERABLE | EXIT CRITERIA |
| --- | --- | --- |
| OP-API-0 | Repository assessment + ADR | Current auth/data/service patterns documented; implementation path selected. |
| OP-API-1 | /api/v1 foundation + auth | Authenticated health/me endpoint; service identity and scopes working. |
| OP-API-2 | Customers/contacts/locations | Search/details pass tests and OpenAPI validation. |
| OP-API-3 | Tickets read/write | JARVIS-scoped identity can list, create, and update tickets with idempotency/audit. |
| OP-API-4 | Projects + contract hardening | Project context available; complete openapi.yaml published. |
| OP-API-5 | Webhook framework | Signed ticket events delivered to a test receiver with retry/deduplication. |


## 16. First Shared Integration Test
```text
1. JARVIS -> GET /api/v1/customers/search?q=T%26S
2. Ops Portal -> returns matching customer
3. JARVIS -> GET /api/v1/tickets?customer_id=<id>&status=open
4. Ops Portal -> returns open tickets
5. JARVIS -> POST /api/v1/tickets + Idempotency-Key
6. Ops Portal -> creates one ticket, logs actor/request ID, returns 201
7. Replay same POST + same Idempotency-Key
8. Ops Portal -> returns original result; no duplicate ticket
```
> **Integration gate — Do not consider the JARVIS/Ops Portal boundary complete until this scenario succeeds against a non-production environment with the real JARVIS service identity.**

## 17. Codex Work Packages
| ID | TASK | DEPENDENCY |
| --- | --- | --- |
| C-001 | Inventory current routing, auth, customer, contact, location, ticket, and project implementations. Produce ADR/API assessment. | None |
| C-002 | Create /api/v1 routing namespace, JSON error middleware, request IDs, and health endpoint. | C-001 |
| C-003 | Implement service identity + bearer-token validation + scope middleware. | C-002 |
| C-004 | Implement customer search/detail endpoints using existing business logic. | C-003 |
| C-005 | Implement contact search and customer locations endpoints. | C-004 |
| C-006 | Implement ticket list/detail endpoints. | C-003 |
| C-007 | Implement ticket create with validation, transaction, Idempotency-Key, audit. | C-006 |
| C-008 | Implement safe ticket PATCH operations + audit. | C-007 |
| C-009 | Implement customer project list/project detail endpoints. | C-004 |
| C-010 | Create and validate docs/openapi.yaml + examples. | C-004–C-009 |
| C-011 | Add integration tests for auth/scopes/all initial endpoints. | C-003–C-010 |
| C-012 | Implement signed webhook emitter for ticket.created/ticket.updated. | After REST integration |


## 18. Prompt Header for Every Codex Task
```text
You are modifying the existing Ops Portal repository.
Authoritative plan: docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN.md
Shared contract: docs/openapi.yaml

Rules:
- Preserve existing production UI behavior.
- Reuse/extract domain logic; do not duplicate business rules.
- Never expose database credentials or generic table CRUD to JARVIS.
- All writes require auth, scope checks, validation, request IDs, audit, and tests.
- Do not introduce SaaS/multi-tenant work in this phase.
- Do not change the shared API contract silently. If a change is necessary, update OpenAPI and create an ADR.
- Keep each change bounded to the requested work package.
```

## 19. Definition of Done
- /api/v1 is versioned and documented.
- JARVIS has a dedicated scoped service identity; no shared admin credentials are used.
- Customer, contact, location, ticket, and project endpoints required by v0.1 are functional.
- Ticket creation is idempotent and audited.
- Error responses and request IDs are consistent.
- OpenAPI matches implemented behavior and includes realistic examples.
- Automated API tests pass in CI/local test runner.
- Existing Ops Portal workflows remain operational.
- JARVIS completes the shared integration test against the real API.

## Cross-Project Coordination Protocol
The two repositories are developed independently but integrate through an explicit contract. Neither coding agent should "solve" a dependency by reaching across the trust boundary.
| CHANGE | OWNER | COORDINATION RULE |
| --- | --- | --- |
| Ops database/schema/business rules | Ops Portal / Codex | JARVIS never assumes schema; only API/OpenAPI changes are consumed. |
| API path/schema/auth contract | Ops Portal / Codex | Update OpenAPI + ADR; JARVIS client updates from contract. |
| Tool naming/policy behavior | JARVIS / Cursor | Ops Portal remains unaware of AI policy beyond scopes. |
| Model/provider changes | JARVIS / Cursor | No Ops Portal change required. |
| Ticket/customer canonical values | Ops Portal / Codex | Expose canonical values; JARVIS validates against contract. |
| Shared integration failure | Both | Reproduce with request_id and API fixture before modifying contract. |


> **Rule of ownership — If a requirement belongs to the other repository, implement an interface/mock and document the dependency. Do not duplicate or bypass the other system.**
