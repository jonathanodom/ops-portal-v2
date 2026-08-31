# CRM Lead Intake Office Queue V1

Sprint 4 adds an organization-scoped Office queue for immutable Commercial Lead Intakes. Staff with `opportunities.view` can list, search, filter, and inspect lead evidence. Staff with `opportunities.manage` can explicitly convert received leads, mark them spam, archive them, or reopen spam/archived records.

The default queue shows newest `received` leads. Navigation shows the current Organization's unresolved received count. Filters cover Open, Converted, Archived, Spam, and All; search covers name, company, email, phone, and service interest.

Conversion delegates entirely to `LeadIntakeConverter`, retaining its conservative matching, ambiguity rejection, transaction, idempotency, and immutable evidence guarantees. Converted records link to their canonical Opportunity. Disposition changes use row locking and safe audits containing only status names and record identity.

The detail view keeps general contact consent and SMS consent visibly separate. It shows normalized intake and marketing attribution but omits raw payload JSON, Turnstile data, IP address, and user agent from the primary Office experience.

Notifications, realtime updates, scoring, assignment, identity merging, analytics, and public/JARVIS conversion endpoints remain deferred.
