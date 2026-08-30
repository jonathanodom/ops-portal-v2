# CRM Lead Intake Foundation V1

Sprint 1 adds an organization-owned `CommercialLeadIntake` source record based on the useful capture and attribution fields in the V1 `LeadSubmission` implementation.

## Domain boundary

`CommercialLeadIntake` preserves normalized inbound identity, request, marketing, consent, and request-evidence data. The existing V2 `Opportunity` remains the active CRM and sales workflow. Future conversion may link an intake to one Opportunity and record its conversion actor/time; Sprint 1 does not create or match Customers, Contacts, or Opportunities.

## Schema groups

- Lifecycle: organization, bounded status/source, received timestamp, and optional error.
- Identity/request: name, phone, email, customer type, ZIP, company, service interest, plan, contact preference, timeline, and details.
- Attribution: originating page, UTM values, and referrer.
- Consent evidence: independent contact and SMS timestamps, IPs, and optional statement versions.
- Request evidence: IP, user agent, canonical JSON payload, and deterministic SHA-256.
- Conversion provenance: nullable Opportunity, conversion timestamp, and converting user.

General contact consent never implies SMS consent. A `preferred_contact` value of `Text` does not create SMS-consent evidence.

## Creation seam

`App\Domain\Commercial\LeadIntakeCreator` accepts already-normalized data, derives a fixed-order canonical payload, hashes it with SHA-256, sets `received_at`, and forces ownership from the supplied Organization rather than caller data. Duplicate hashes are retained in this sprint.

## Scope

This foundation has no route or UI. It does not add a public endpoint, CORS, Turnstile, honeypot validation, throttling, matching, conversion, notifications, scoring, reporting, or an Office queue.

Sprint 2 will add the guarded public POST boundary and use this creator after request normalization and consent validation.
