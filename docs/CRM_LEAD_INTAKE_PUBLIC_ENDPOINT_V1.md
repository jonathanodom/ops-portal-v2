# CRM Lead Intake Public Endpoint V1

`POST /api/public/v1/leads` accepts the existing website-compatible camelCase lead payload and stores exactly one organization-owned `CommercialLeadIntake`. It is separate from the authenticated JARVIS `/api/v1` routes.

## Request behavior

- Required identity and request fields are validated and strings are trimmed with unsafe control characters removed.
- Residential/Individual normalize to `Individual`; business, commercial, church/nonprofit, and builder/contractor labels normalize to `Business`.
- Business-like submissions require a company.
- `serviceInterest` must match the configured allowlist.
- The blank `website` honeypot rejects populated values with a generic validation message.
- General `consent` is required and records its own timestamp, IP, and statement version.
- `smsConsent` is optional and independent. A `preferredContact` of `Text` never creates SMS consent evidence.
- When `TURNSTILE_SECRET_KEY` is configured, `turnstileToken` is required and verified server-side. The token is never persisted.

## Destination and throttling

The caller cannot select an Organization. `LEAD_INTAKE_ORGANIZATION_SLUG` resolves one exact active Organization; without it, the resolver requires exactly one active Organization. Ambiguous or unavailable destinations fail safely. The route is limited to five submissions per minute per IP by default and uses the existing API-only 256 KB request-size boundary.

## Response and scope

Successful storage returns HTTP 201 with only:

```json
{"message":"Request received. NewDay Tech will follow up soon."}
```

Validation and throttling use the portal's safe JSON API error envelope. This endpoint stores only: it sends no notifications and creates no Customer, Contact, or Opportunity. Exact production browser-origin/CORS integration remains deferred. Sprint 3 will add controlled Lead Intake-to-Opportunity conversion.
