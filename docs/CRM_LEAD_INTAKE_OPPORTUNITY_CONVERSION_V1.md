# CRM Lead Intake Opportunity Conversion V1

Sprint 3 adds an internal, transactional conversion from immutable `CommercialLeadIntake` evidence to an editable `Opportunity`. The public lead endpoint remains store-only; no Office queue or conversion route is included.

## Identity resolution

Resolution is restricted to the intake Organization and checks, in order, one active Contact by normalized email, one active Contact by normalized phone, one Customer by normalized email, then one Customer by normalized phone. Multiple candidates at any step stop conversion for manual resolution. Names and company names are never used as identity matches. A strong match to an inactive Customer fails the existing Opportunity validation instead of silently creating a duplicate identity.

When there is no strong match, an Individual lead creates an active individual Customer. A Business lead creates an active business Customer and an active preferred Contact. ZIP is retained only on the intake; conversion never fabricates a Service Location.

## Opportunity mapping and safety

The converter reuses the existing Opportunity workflow for organization scoping, numbering, the default New stage, actor attribution, and audit recording. New Opportunities use normal priority, zero estimated value, `website` lead source, and a title composed from service interest and Customer display name. Existing conventions assign the converting active member as owner.

Conversion locks the intake and commits identity creation, Opportunity creation, conversion provenance, and safe audit metadata in one transaction. A converted intake returns its existing Organization-scoped Opportunity without changing its original conversion timestamp or actor. Archived, spam, and failed intakes are rejected. Source payload, SHA-256 hash, consent, attribution, and request evidence are never modified.

Sprint 4 may add the authorized Office New Leads queue and explicit conversion controls.
