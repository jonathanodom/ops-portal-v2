# CRM Lead Intake V1 Production Readiness

## Release scope

CRM Lead Intake V1 uses one immutable, organization-owned `CommercialLeadIntake` source record for website and authenticated manual inquiries. Received Leads appear in the Office Leads queue and convert through `LeadIntakeConverter` into canonical Customer, optional Contact, and Opportunity records. Conversion matching is exact and organization scoped; repeated conversion returns the existing Opportunity.

NewDay Home Quick Add links to the manual Lead form and the existing authoritative Service Ticket creation route. It does not introduce a second Ticket backend.

## Production configuration

The Ops Portal production environment requires:

```dotenv
LEAD_INTAKE_ORGANIZATION_SLUG=<active destination organization slug>
LEAD_INTAKE_RATE_LIMIT_PER_MINUTE=5
LEAD_INTAKE_ALLOWED_ORIGINS=https://newdaytech.net,https://www.newdaytech.net
LEAD_CONTACT_CONSENT_VERSION=website-v1
LEAD_SMS_CONSENT_VERSION=website-v1
TURNSTILE_SECRET_KEY=<Cloudflare secret when verification is enabled>
```

The marketing-site build requires framework-appropriate public configuration equivalent to:

```dotenv
PUBLIC_LEAD_API_BASE_URL=https://portal.newdaytech.net
TURNSTILE_SITE_KEY=<Cloudflare public site key when verification is enabled>
```

Secrets must never be committed or rendered in the website bundle. The website sends JSON to `POST https://portal.newdaytech.net/api/public/v1/leads`. It must not send an Organization ID, internal status, Opportunity ID, or bearer credential.

General contact permission and SMS consent remain independent. `preferredContact=Text` never implies SMS consent. Website confirmations use the configured website versions and the public request IP evidence. Authenticated manual confirmations use `manual-v1`, server timestamps, and null customer/consent IP fields.

## Security and workflow validation

The automated gate covers:

- valid public `201` responses and safe `422`, `429`, `413`, and unavailable-organization errors;
- honeypot rejection and Turnstile disabled, required, failed, and successful paths;
- server-owned Organization resolution and absence of internal identifiers in public responses;
- exact CORS access for `https://newdaytech.net` and `https://www.newdaytech.net`, with no wildcard, credentials, or JARVIS API inheritance;
- deterministic canonical payload hashing without retained Turnstile tokens;
- separate contact and SMS consent evidence;
- exact email/phone matching, ambiguity rejection, organization isolation, rollback, and conversion idempotency;
- Office list, search, detail, dispositions, authorization, inactive membership, and unresolved badge behavior;
- manual Lead creation, safe audit attribution, validation, queue visibility, and independent consent;
- Home Quick Add capability filtering and reuse of the canonical Service Ticket creation route.

## Browser and accessibility validation

The beta browser scenario signs in as Super Admin and validates Leads, New Lead, and Home Quick Add at 390, 768, 1280, 1440, and 1920 pixel widths. It verifies keyboard/touch-sized Quick Add controls, no horizontal overflow, no serious or critical axe findings, manual Lead creation, separate consent display, spam/reopen, conversion, converted-state idempotency, and the existing Service Ticket create destination.

## Website and production status

The marketing-site source repository was not available in the workspace or the accessible `jonathanodom` GitHub repositories during Sprints 5–7. The local `NewDayTech.net` OneDrive directory was empty. Therefore:

- the production website's endpoint configuration and payload mapping could not be inspected or patched;
- retirement of the old V1 `/api/leads` destination and absence of dual-writing could not be verified in source;
- a controlled website-to-production `201` submission was not performed;
- production Turnstile site-key wiring could not be verified;
- no deployment or production data mutation was attempted.

Live production diagnosis on August 31, 2026 confirmed that the deployed Next.js
bundle falls back to `https://portal.newdaytech.net/api/leads` and submits the SMS
checkbox as `smsOptIn`. The obsolete path returns `404` without a CORS
allow-origin header, which the browser surfaces as `Failed to fetch`. The V2
endpoint, HTTPS, Apache routing, validation response, request IDs, rate limiting,
and exact apex/`www` CORS policy are operating correctly.

These are marketing-site rollout blockers, not Ops Portal application-code
failures. Apply the exact source patch and verification procedure in
`docs/CRM_PRODUCTION_LEAD_INTAKE_REPAIR_V1.md`, remove every old `/api/leads`
reference, and complete the controlled acceptance smoke before approving
production rollout.

The required API target is `https://portal.newdaytech.net`. Do not hardcode `staging-portal.newdaytech.net` into the production website. Hostname cutover and routing must be confirmed during the owner-approved deployment window.

## Controlled acceptance checklist

Website:

1. Submit one clearly identified test inquiry from the real marketing form.
2. Confirm one `201` response and exactly one `CommercialLeadIntake` with `source=website`.
3. Confirm UTM, referrer, customer type, service interest, timeline, and both consent states.
4. Confirm the Lead appears once as Open in Office and is not automatically converted.

Manual:

1. Use Home **Quick Add → New Lead**.
2. Submit a manual Lead and confirm `source=manual`, queue visibility, and independent consent evidence.
3. Convert it and verify the expected Customer/Contact and Opportunity.
4. Repeat the conversion request and confirm no duplicate records.

Service Ticket:

1. Use Home **Quick Add → New Service Ticket**.
2. Confirm the existing full Ticket creation form and workflow are used.

## Deployment and rollback

No deployment is authorized by this PR. After later owner approval, deploy the Ops Portal normally, configure the environment, verify `/up`, queue health, Office Leads, Opportunities, Home, and normal Ticket creation, then deploy the marketing-site integration and run the controlled smoke.

If the marketing integration fails, roll back the marketing-site deployment without dual-writing to V1 and V2. If the Office release fails, use the normal application rollback. Do not reset the database or delete Lead Intake records.

## Validation results

Local validation on August 31, 2026:

- focused Lead Intake and canonical Ticket-create regression: 48 passed, 368 assertions;
- full PHPUnit: 642 passed, 2 intentional skips, 4,928 assertions;
- isolated beta fixture validation: exact expected counts and SQLite integrity passed;
- Beta Hardening: 8 passed, 56 assertions;
- ten-run beta benchmark: all budgets passed; highest p95 was 33.6 ms;
- Playwright/axe: 37 passed and 37 project-conditional skips, including the CRM workflow at 390, 768, 1280, 1440, and 1920 pixels;
- Composer validation and security audit, Pint, 222 compiled Blade files, Vite production build, and `git diff --check`: passed.

The draft PR's GitHub MySQL migration and full CI results remain the authoritative cross-database gate. CRM Lead Intake V1 is code-ready only when those checks are green. It is not production-ready until the website-source, V1-retirement, hostname, and controlled-E2E blockers above are cleared and the owner explicitly approves rollout.
