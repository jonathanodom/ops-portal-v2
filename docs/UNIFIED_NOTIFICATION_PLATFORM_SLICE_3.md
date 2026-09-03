# Unified Notification Platform — Slice 3

Slice 3 adds centralized queued staff email delivery and the first business-event integration: valid public website lead submissions.

## New Lead event

After `LeadIntakeCreator` commits a valid website lead, `NewLeadSubmittedNotifier` publishes one `lead.submitted` event through the unified notification manager. Active organization members with effective `opportunities.manage` capability receive their own in-app recipient record. Email is selected by the existing channel preference system and queued only when the recipient has a usable account email.

Manual Office leads do not emit the website-submission event. Validation, honeypot, Turnstile, and rejected public submissions create no notification or email job.

## Email delivery

`StaffNotificationEmailChannel` claims each email delivery once and dispatches `DeliverPortalNotificationEmail` after commit. The job uses the normalized event data, the recipient User email, and branded HTML/text templates. It retries through the configured Laravel queue and records safe delivery timestamps. Final queue failures use the existing queue-failure incident handling and safe structured logging.

Production requires a configured Laravel mail transport (`MAIL_*`) and a continuously running queue worker. The existing default is the database queue; local defaults log mail instead of sending it.

## Deferred

Browser Web Push, VAPID, service workers, assignment/schedule events, Office Updates, and the complete preferences interface remain deferred.
