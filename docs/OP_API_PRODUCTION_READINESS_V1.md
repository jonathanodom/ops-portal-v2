# JARVIS API V1 production-readiness runbook

## Scope and safety boundary

This runbook covers the existing 11-operation `/api/v1` contract only. Every
business endpoint requires both a Sanctum token ability and the corresponding
active Organization membership capability. The API does not add a second copy
of Customer, Service Ticket, Project, or audit rules.

Production deployment and JARVIS activation are separate owner-controlled
gates. Never create a production token during build, migration, seeding, CI, or
dormant deployment.

## Runtime controls

- `JARVIS_SERVICE_TOKEN_TTL_DAYS=90` controls explicit token expiration.
- `JARVIS_API_READ_LIMIT_PER_MINUTE=120` and
  `JARVIS_API_WRITE_LIMIT_PER_MINUTE=30` control independent API-only rate
  buckets per Organization and authenticated service identity.
- `JARVIS_API_MAX_REQUEST_BYTES=262144` limits JSON requests to 256 KiB.
- `Idempotency-Key` is required for Ticket POST/PATCH, must be 8–128
  characters, and is bound to the method, concrete path, and normalized
  validated payload by SHA-256.

The standard PHP/web-server request boundary remains an outer limit. The
application API limit is intentionally smaller and does not affect browser or
upload routes. Rate limiting uses the configured Laravel cache store; Redis is
not required solely for this API.

## Service identity controls

`php artisan jarvis:create-service-account`:

- accepts only an active Organization selected by slug or ID;
- refuses to convert a human user into a service account;
- refuses to reuse one service identity across Organizations;
- assigns only the `jarvis_service` role and its approved API capabilities;
- creates a token with the configured expiry and exact approved abilities;
- prints the plaintext token once and prints its expiration;
- revokes prior tokens before replacement when `--rotate` is supplied.

The `service_account` user status is rejected by the human login flow. No
migration or seeder creates a plaintext token.

## Stage A — deploy dormant

Only after owner approval and merge:

1. Create and verify the normal production database backup.
2. Deploy through the normal portal process.
3. Run additive migrations; do not reset or reseed operational data.
4. Do **not** run `jarvis:create-service-account` yet.
5. Verify `/up`.
6. Smoke-test authenticated Office and Field UI workflows, including Customer,
   Service Ticket, Commercial, Billing, and Invoice views.
7. Confirm the queue worker is running and review queue/operational health.
8. Confirm application logs contain request IDs but no Authorization header or
   bearer-token value.

## Stage B — create the controlled production identity

Run deliberately on production after Stage A succeeds:

```shell
php artisan jarvis:create-service-account --organization=<production-org-slug>
```

Copy the one-time token directly into the approved JARVIS secret/environment
store. Do not place it in shell history, tickets, chat, documentation, source,
CI variables visible to unapproved users, or application logs. Record the
printed expiry in the authorized credential-rotation schedule without recording
the token.

## Stage C — production API smoke

Use a designated test Customer, Location, and Ticket context. Never mutate a
real customer Ticket solely for smoke testing.

1. `GET /api/v1/me` and verify the Organization and expected scopes.
2. Search and read the designated Customer.
3. List that Customer's Locations.
4. List and read designated Service Tickets.
5. Create one controlled Ticket using a fresh UUID `Idempotency-Key`.
6. Replay the exact create with the same key; verify the same Ticket and no
   duplicate.
7. Change the valid body while retaining the key; verify
   `409 idempotency_key_reused` and no write.
8. PATCH the controlled Ticket with a fresh key and append safe test text.
9. Replay the PATCH; verify the append occurs once.
10. Use a deliberately narrowed test token to verify `403 forbidden`.
11. Use an invalid token value to verify `401 unauthenticated`.
12. Verify every response has an `X-Request-ID` matching `meta.request_id`.

Revoke the narrowed test token immediately after the smoke test.

## Stage D — activate JARVIS

1. Set the production Ops API base URL in JARVIS.
2. Set the bearer token only in the approved JARVIS secret/environment store.
3. Reload JARVIS through its normal process.
4. Call `/api/v1/me`.
5. Perform one controlled end-to-end Customer lookup.
6. Enable normal use only after those checks succeed.

## Rotation and rollback

Rotate before expiration or immediately after suspected exposure:

```shell
php artisan jarvis:create-service-account --organization=<production-org-slug> --rotate
```

Update the JARVIS secret and reload it. The old token must return 401.

If JARVIS misbehaves, revoke or rotate its token first. The API code may remain
deployed while the normal Portal continues operating. Do not reset the database,
delete audit history, weaken authorization, or rewrite idempotency records.

## Observability and incident checks

- Correlation is carried by `X-Request-ID` and structured logging context.
- Writes use the service-account User as the audited actor.
- Cross-Organization access is a safe 404 and records the existing safe audit
  event where supported.
- Validation, authentication, authorization, conflict, size, rate, and server
  failures use the API envelope without stack traces.
- Fingerprints contain only a SHA-256 digest; request payloads and bearer tokens
  are not placed in idempotency metadata, audits, or logs.
- Repeated 401, 403, 409, 413, or 429 responses should be investigated using
  request IDs, not by logging credentials or raw sensitive bodies.

## Validation record

- Starting `main`: `77acb835e3b260cdebba85a4cb2d8bc1275b65e6`.
- Starting API head: `9bf82bc722234a177f25da3fa0ad9c23a554d086`
  (9 ahead, 0 behind).
- Baseline after installing the already-locked `symfony/yaml` dependency: 583
  passed, 2 intentional skips, 4,502 assertions.
- The previously reported OpenAPI failure was local dependency drift: the
  package was declared and locked but absent from `vendor`. No test was hidden
  or skipped; a normal `composer install` restored it.

- Focused API validation: 128 passed, 2 intentional skips, 444 assertions.
- Full local regression: 597 passed, 2 intentional skips, 4,586 assertions in
  151.25 seconds.
- Composer strict validation and security audit, Pint, compiled-Blade syntax
  lint (218 files), Vite production build, Redocly OpenAPI lint, and
  `git diff --check` passed.
- The additive idempotency migration was exercised through migrate, rollback,
  and re-migrate against an isolated SQLite database. The active local database
  was not reset or replaced.

MySQL and the repository's remaining full-validation jobs are recorded in the
draft PR **Prepare JARVIS API V1 for production** before owner review.
