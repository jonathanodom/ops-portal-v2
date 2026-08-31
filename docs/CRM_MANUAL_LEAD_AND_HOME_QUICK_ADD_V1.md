# CRM Manual Lead and Home Quick Add V1

## Manual Lead entry

Authorized Office users with `opportunities.manage` can open **New Lead** from the Leads workspace or NewDay Home. The authenticated form creates the same organization-owned `CommercialLeadIntake` used by website submissions through `LeadIntakeCreator`.

Manual entries always use `source=manual` and `status=received`. Office users cannot select or override those values, attribution, or organization scope. Manual entry does not use public CORS, Turnstile, the website honeypot, or the public IP throttle.

General contact permission and SMS consent are independent confirmations. A checked confirmation receives the `manual-v1` evidence version and server timestamp. An unchecked confirmation remains null. Selecting Text as the preferred contact method never implies SMS consent. Customer and consent IP addresses are not inferred from the authenticated Office user's request.

Creation records `commercial_lead_intake.created_manual` with actor, Lead ID, and source only. Lead contact information and inquiry details are excluded from audit metadata. Successful creation redirects to the canonical Lead detail and the Lead remains available in the normal Open queue and conversion workflow.

## Home Quick Add

NewDay Home provides a keyboard- and touch-accessible **Quick Add** menu when at least one action is authorized:

- **New Service Ticket** requires `dispatch.manage` and links directly to the existing `office.service-tickets.create` route. No alternate Ticket form, controller, workflow, or API was added.
- **New Lead** requires `opportunities.manage` and links to the authenticated manual Lead form.

Each option is omitted independently when its capability is unavailable. The menu is absent when neither action is authorized.

## Sprint 7 handoff

Sprint 7 must branch from `main` only after this Sprint 6 PR is approved and merged. Its stabilization gate should validate website and manual intake, queue/conversion idempotency, Home shortcuts, the authoritative Ticket workflow, production configuration, MySQL, the full regression suite, and browser accessibility. Sprint 6 does not merge, deploy, or run that release gate.
