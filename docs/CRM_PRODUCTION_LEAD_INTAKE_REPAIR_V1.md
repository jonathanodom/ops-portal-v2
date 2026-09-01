# CRM Production Lead Intake Repair V1

## Diagnosis

Production was inspected on August 31, 2026. The failure is in the deployed
marketing-site bundle, not the Ops Portal lead endpoint.

The live `newdaytech.net` contact bundle currently falls back to:

```text
https://portal.newdaytech.net/api/leads
```

That obsolete V1 path returns `404` without a CORS allow-origin header. The
browser consequently reports `TypeError: Failed to fetch` instead of exposing
the HTTP response to the page.

The same bundle submits the SMS checkbox as `smsOptIn`. The V2 contract expects
the independent boolean field `smsConsent`. Correcting only the URL would make
the request reachable, but would not preserve explicit SMS-consent evidence.

## Verified Ops Portal state

- `portal.newdaytech.net` resolves to `149.28.251.125` and serves the V2 Laravel
  application over valid HTTPS through Apache.
- `GET https://portal.newdaytech.net/up` returns `200` with an `X-Request-ID`.
- `OPTIONS https://portal.newdaytech.net/api/public/v1/leads` returns `204` for
  `https://newdaytech.net` and `https://www.newdaytech.net`.
- The preflight permits only `POST` and `OPTIONS`, accepts only `Accept` and
  `Content-Type`, and does not enable credentials.
- An unapproved origin receives no `Access-Control-Allow-Origin` header.
- An empty JSON `POST` from the approved origin reaches Laravel and returns the
  expected safe `422` validation response with rate-limit and request-ID headers.
- The obsolete `/api/leads` path returns `404` and is not part of the V2 API.

No compatibility route should be added for `/api/leads`. Doing so would retain
an obsolete contract and conceal incorrect production website configuration.

## Required marketing-site patch

The live website is built with Next.js, but its source repository was not
available in the workspace or the accessible `jonathanodom` repositories. Apply
this change in the repository that produces `newdaytech.net`.

Configure the production build:

```dotenv
NEXT_PUBLIC_LEAD_ENDPOINT=https://portal.newdaytech.net/api/public/v1/leads
NEXT_PUBLIC_TURNSTILE_SITE_KEY=<public-site-key-if-enabled>
```

Never place the Turnstile secret, an organization ID, or a bearer token in the
website configuration or bundle.

Rename the SMS checkbox to `smsConsent`, or explicitly map the existing
`smsOptIn` control to `smsConsent`. Convert both consent controls to booleans;
do not infer SMS consent from a Text contact preference.

```js
const raw = Object.fromEntries(new FormData(form).entries());

const payload = {
  firstName: raw.firstName,
  lastName: raw.lastName,
  phone: raw.phone,
  email: raw.email,
  customerType: raw.customerType,
  zip: raw.zip,
  company: raw.company || null,
  serviceInterest: raw.serviceInterest,
  selectedPlan: raw.selectedPlan || null,
  preferredContact: raw.preferredContact,
  timeline: raw.timeline,
  details: raw.details,
  originatingPage: window.location.pathname,
  utmSource: searchParams.get('utm_source'),
  utmMedium: searchParams.get('utm_medium'),
  utmCampaign: searchParams.get('utm_campaign'),
  utmTerm: searchParams.get('utm_term'),
  utmContent: searchParams.get('utm_content'),
  referrer: document.referrer.slice(0, 2048),
  website: raw.website || '',
  consent: raw.consent === 'true',
  smsConsent: (raw.smsConsent ?? raw.smsOptIn) === 'true',
  turnstileToken: turnstileToken || null,
};
```

Post the payload once as JSON with `Accept: application/json` and
`Content-Type: application/json`. Disable the submit control and reject another
submission while the request is pending. Preserve entered values for `422`,
`429`, network, and server failures. Show the existing success state only after
`201`; never show raw server exception text or automatically retry a failed
request.

Search the source and built configuration for all occurrences of `/api/leads`,
`staging-portal.newdaytech.net`, and any old portal hostname. Remove them rather
than dual-writing to V1 and V2.

## Production configuration verification

During the authorized deployment window, verify the Ops Portal environment:

```dotenv
APP_URL=https://portal.newdaytech.net
LEAD_INTAKE_ALLOWED_ORIGINS=https://newdaytech.net,https://www.newdaytech.net
LEAD_INTAKE_ORGANIZATION_SLUG=<active-organization-slug>
```

If Turnstile is enabled, verify that `TURNSTILE_SECRET_KEY` belongs to the same
widget as the website's public site key. Refresh Laravel configuration using the
normal deployment procedure (`php artisan config:clear` followed by
`php artisan config:cache`) and confirm `/up` and queue health.

## Acceptance smoke

After deploying the marketing-site patch, inspect the browser Network panel and
confirm:

1. Preflight to `/api/public/v1/leads` returns `204` with the exact website
   origin.
2. One controlled, non-customer submission returns `201`.
3. Exactly one `CommercialLeadIntake` appears as Open in the Office Leads queue.
4. Its source, UTM values, originating page, referrer, and the two independent
   consent values match the request.
5. No Customer, Contact, or Opportunity is created automatically.

No production submission was made during diagnosis because that would create a
real production record without an approved test-data action.

## Rollback

If the repaired form misbehaves, roll back only the marketing-site deployment.
Do not restore V1 dual-writing, add the obsolete route, reset the Ops Portal
database, or delete captured Lead Intake records. The guarded V2 endpoint may
remain deployed.

