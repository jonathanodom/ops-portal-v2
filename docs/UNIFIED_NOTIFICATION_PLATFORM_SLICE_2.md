# Unified Notification Platform — Slice 2

Slice 2 adds the authenticated in-app Notification Center on top of the Slice 1 domain foundation.

## Experience

- Office and Field shells display an accessible notification bell.
- The badge is hidden at zero, displays the unread count through 99, and uses `99+` above that.
- The panel loads the ten newest in-app notifications and handles loading, empty, and failure states without disrupting the shell.
- `/notifications` provides a paginated, newest-first history using the authorized user's appropriate Office or Field shell.
- Opening a notification marks it read before following its internal deep link. Individual and mark-all-read actions are idempotent.

## Security and scope

Every query is constrained by the active organization, authenticated user, and selected `in_app` channel. IDs belonging to another user or organization return 404. Notification links must be internal application paths; legacy unsafe values fall back to notification history. Target routes continue enforcing their own authorization.

## Deferred

Email delivery, browser push, service workers, notification preferences UI, domain-event integrations, and Office Update publishing remain deferred to later slices.
