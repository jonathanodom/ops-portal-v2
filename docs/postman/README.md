# Postman collection — `/api/v1`

Covers OP-API-1 (auth/scopes) and OP-API-2 (customers, contacts, locations)
from `docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md`.

## Setup (local dev, `http://ops-portal-v2.test/`)

1. Pull `feat/op-api-1-foundation-and-service-identity` and install as usual:

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

4. In Postman:
   - Import `ops-portal-v2-api-v1.postman_collection.json`.
   - Import `ops-portal-v2-local.postman_environment.json` and select it.
   - Set the environment's `token` variable to the value the command printed.
   - Confirm `base_url` is `http://ops-portal-v2.test/api/v1` (already the
     environment default).

5. Run **Happy path** top to bottom. The `customers/search` request stores
   the first matching customer's `id` into the `customer_id` collection
   variable automatically, so the following two requests use a real record.
   If your local database has no customers yet, create one first (e.g.
   through the office UI, or `php artisan tinker`), then re-run.

6. Run **Negative cases**. These don't require any local data — they
   exercise missing/invalid tokens, a missing required `q` parameter, and a
   guaranteed-nonexistent customer ID (confirming no internal class names or
   stack traces leak into 404 responses).

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
