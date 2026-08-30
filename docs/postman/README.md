# Postman collection — `/api/v1`

Covers OP-API-1 (auth/scopes), OP-API-2 (customers, contacts, locations),
OP-API-3 (ticket list/detail/create with idempotency), and OP-API-4
(ticket PATCH updates + customer/project reads) from
`docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md`.

## Setup (local dev, `http://ops-portal-v2.test/`)

1. Pull `feat/op-api-production-readiness-v1` and install as usual:

   ```powershell
   composer install
   npm ci && npm run build
   ```

2. Apply the new migrations without touching existing data:

   ```powershell
   composer phase:update
   ```

   (or `php artisan migrate` if you don't already have the `phase:update`
   Composer script wired up locally). This adds the `personal_access_tokens`
   table and the `jarvis_service` role/capabilities; it does not modify or
   remove any existing customers, contacts, tickets, or users.

3. Create the JARVIS service identity and copy the printed token — it is
   shown exactly once:

   ```powershell
   php artisan jarvis:create-service-account
   ```

   If you have more than one active `Organization` locally, pass
   `--organization=<slug-or-id>`.

   The command prints the token expiration (90 days by default). Use
   `--rotate` to revoke prior tokens before issuing one replacement. Never
   place a real production token in this collection, source, docs, or logs.

4. In Postman:
   - Import `ops-portal-v2-api-v1.postman_collection.json`.
   - Import `ops-portal-v2-local.postman_environment.json` and select it.
   - Set the environment's `token` variable to the value the command printed.
   - Confirm `base_url` is `http://ops-portal-v2.test/api/v1` (already the
     environment default).

5. Run **Happy path** top to bottom. It captures `customer_id`, `location_id`,
   and `contact_id` collection variables from the search/locations/contacts
   responses, so the following folders use real records. If your local
   database has no customers yet, create one first (e.g. through the office
   UI, or `php artisan tinker`), then re-run.

6. Run **Tickets (C-006/C-007/C-008)** top to bottom. It creates one real
   ticket using a fresh `Idempotency-Key` (generated once and reused),
   confirms it's retrievable, **replays the exact same create request with
   the same key** and asserts it returns the *same* ticket with no
   duplicate, changes the body while retaining the key and verifies the
   stable `409 idempotency_key_reused` response, confirms the ticket list
   shows exactly one open ticket, then
   **PATCHes** that ticket's priority and appends to its description
   (asserting the description was appended, not overwritten). Re-running
   this folder generates a new `Idempotency-Key`, so it's safe to run
   repeatedly — each run creates one new ticket, not one per request.

   Idempotency keys must be opaque strings between 8 and 128 characters. A
   validation failure does not consume a key.

7. Run **Projects (C-009)**. Lists projects for the customer captured
   earlier and fetches one project's detail. If your local database has no
   projects yet, this returns an empty list / 404 — that's expected, not a
   failure; create a project through the office UI first if you want to
   exercise it fully.

8. Run **Negative cases**. These don't require any local data — they
   exercise missing/invalid tokens, a missing required `q` parameter, and a
   guaranteed-nonexistent customer ID (confirming no internal class names or
   stack traces leak into 404 responses).

API reads and writes have separate per-identity/organization rate buckets
(120/minute and 30/minute by default). A `429` response uses the normal API
envelope and retains `X-Request-ID`. JSON API bodies are limited to 256 KiB by
default; browser and upload limits are unchanged.

## Optional: the insufficient-scope (403) request

The last request in **Negative cases** needs a token that was issued with
*fewer* abilities than `jarvis_service` grants (e.g. only `tickets.read`).
Create one with:

```powershell
php artisan tinker
>>> $u = App\Models\User::where('email', 'jarvis-core@service.newdaytech.net')->first();
>>> $u->createToken('narrow-test', ['tickets.read'])->plainTextToken
```

Paste that into the `narrow_token` environment variable. Skip this request
if you don't want to create an extra token.
