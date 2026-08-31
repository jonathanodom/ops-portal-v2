# CRM Lead Intake Marketing Site Integration V1

## Delivery status

The Ops Portal side of Sprint 5 is implemented. The public lead endpoint is available at:

`POST https://portal.newdaytech.net/api/public/v1/leads`

The source repository for `newdaytech.net` was not available during this sprint. The local `NewDayTech.net` OneDrive directory was empty and no marketing-site repository was available in the `jonathanodom` GitHub account. The website was therefore not recreated or changed, and the end-to-end production acceptance smoke has not been claimed.

## Ops Portal configuration

Set the production environment value below and refresh the Laravel configuration cache during the normal deployment:

```dotenv
LEAD_INTAKE_ALLOWED_ORIGINS=https://newdaytech.net,https://www.newdaytech.net
```

CORS is limited to `api/public/v1/leads`, `POST`/`OPTIONS`, and the `Accept` and `Content-Type` request headers. Credentials are disabled. The authenticated JARVIS API does not inherit marketing-site CORS access.

The endpoint retains the Sprint 2 controls: JSON request-size limit, IP rate limiting, honeypot validation, optional Turnstile verification, safe API errors, and server-side organization resolution. Never send an organization identifier or bearer token from the website.

## Required marketing-site patch

Use the site's existing build/environment convention to configure:

```text
PUBLIC_LEAD_API_URL=https://portal.newdaytech.net/api/public/v1/leads
PUBLIC_TURNSTILE_SITE_KEY=<Cloudflare public site key, only when Turnstile is enabled>
```

The exact environment variable names may follow the site's framework conventions. The API URL and Turnstile site key are public configuration; the Turnstile secret remains only in the Ops Portal environment.

Search the marketing-site source and remove every old lead destination, including `staging-portal.newdaytech.net`, the V1 portal hostname, `/api/leads`, and HTML form actions targeting those locations. Submit only to the V2 URL; do not dual-write.

Send JSON with this shape:

```json
{
  "firstName": "Jane",
  "lastName": "Smith",
  "phone": "940-555-1212",
  "email": "jane@example.com",
  "customerType": "Residential",
  "zip": "76450",
  "company": null,
  "serviceInterest": "Wi-Fi & Networking",
  "selectedPlan": null,
  "preferredContact": "Phone",
  "timeline": "Within 30 days",
  "details": "Customer-entered request",
  "originatingPage": "/about-contact/",
  "utmSource": "google",
  "utmMedium": "cpc",
  "utmCampaign": "wifi",
  "utmTerm": null,
  "utmContent": null,
  "referrer": "https://www.google.com/",
  "website": "",
  "consent": true,
  "smsConsent": false,
  "turnstileToken": null
}
```

Do not send `organization_id`, internal status, Opportunity identifiers, or credentials. General contact consent and SMS consent are separate values; `preferredContact: "Text"` must never set `smsConsent` implicitly. Keep the `website` honeypot hidden from people and blank for legitimate submissions.

Capture `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, and `utm_content` from the current URL and map them to the camel-case fields above. Send the current path as `originatingPage` and a reasonably bounded `document.referrer` as `referrer`. If Turnstile is enabled, render it using the public site key and map its one-time response to `turnstileToken`.

## Submission behavior

Keep the existing form design and confirmation copy. On submit:

1. Prevent the default form action, disable the submit button, and reject another submit while the request is pending.
2. Send one JSON `POST` with `Accept: application/json` and `Content-Type: application/json`.
3. On `201`, show the existing success state and clear the form only after the server response.
4. On `422`, associate returned field errors with the existing controls and preserve all entered values.
5. On `429`, show a retry-later message without automatically retrying.
6. On `500` or `503`, network failure, or timeout, show a generic temporary-failure message, preserve values, and allow an explicit retry.
7. Never render raw exception text or report success before the `201` response.

An implementation outline is:

```js
if (submitting) return;
submitting = true;
submitButton.disabled = true;

try {
  const response = await fetch(PUBLIC_LEAD_API_URL, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });

  if (response.status === 201) showSuccess();
  else if (response.status === 422) showFieldErrors(await response.json());
  else if (response.status === 429) showRetryLater();
  else showTemporaryFailure();
} catch {
  showTemporaryFailure();
} finally {
  submitting = false;
  submitButton.disabled = false;
}
```

## Acceptance smoke

After the website patch is reviewed and deployed to a non-production test context, submit one clearly identified controlled lead and verify:

- the browser receives `201`;
- exactly one `CommercialLeadIntake` exists;
- it appears once as Open in the Office Leads queue;
- source is `website`;
- UTM, originating page, and referrer attribution are correct;
- general and SMS consent match the submitted checkboxes independently;
- no Customer, Contact, or Opportunity is created automatically.

Do not use a real customer's data for this smoke test. Production deployment and the smoke test require separate owner authorization.

## Rollback

If the website integration misbehaves, roll back the marketing-site deployment to its previous version. Do not silently restore V1 dual-writing. The V2 endpoint and stored intake records may remain deployed because they are independently guarded and do not auto-convert leads.
