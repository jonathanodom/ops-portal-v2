# CRM Lead Follow-Up V1

## Engagement states

Office lead follow-up uses a separate engagement state: New, Attempted Contact, Left Voicemail, Contacted, Waiting on Customer, Follow-Up Needed, Qualified, Not Qualified, or Closed / No Response. Existing records with no stored engagement state display as New.

The intake lifecycle remains unchanged (`received`, `converted`, `archived`, `spam`, and `failed`). Converted is derived from that lifecycle and cannot be set through follow-up tracking. Follow-up changes never convert, archive, or otherwise disposition a lead automatically.

## Follow-up behavior

Authorized Commercial managers can change the engagement state, set or clear an organization-local next follow-up date and time, and add an optional note while an intake remains received/open. The queue supports independent lifecycle and engagement filters, including due-today and overdue follow-ups.

Each change records the actor and timestamp on the intake. Status changes, follow-up-date changes, and notes are also stored as append-only lead activities. The Office timeline combines those activities with the immutable received timestamp and existing conversion provenance. Audit metadata contains safe field and state names; note contents remain only in the authorized lead activity.

Public lead submissions cannot set engagement fields. New and existing public or manual intakes begin with the New display fallback.

## Deferred work

Email composition, inbound email threading, SMS sending, automated reminders or transitions, lead scoring, assignment automation, notifications, and JARVIS CRM actions are not included.
