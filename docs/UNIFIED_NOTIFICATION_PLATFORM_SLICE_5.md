# Unified Notification Platform — Slice 5

Slice 5 integrates Visit crew assignment with the existing normalized notification platform.

## Assignment behavior

- Each active portal user newly added to a Visit crew receives one `ticket.assigned` notification.
- Saving the same crew again does not notify anyone.
- Reassignment notifies only newly added crew. Removed users receive no notification because no established removal-notification policy exists.
- Removing all crew creates no “new assignment” notification.
- Return Follow-Up Tickets use the same Visit scheduler and therefore receive the same generic assignment event without a duplicate return-specific event.

The event uses the Service Ticket as its related entity and the assigned Visit's default Field workspace as its authenticated action. In-app is required; email and browser push honor existing channel preferences and availability.

Assignment persistence completes before notification publication. Notification publication failures are safely logged using identifiers and failure type only. Existing asynchronous email and push jobs isolate external delivery failures from assignment state.

## Deferred

Assignment-removed notifications, schedule/reschedule/reminder events, office announcements, customer notifications, SMS, native push, and the full preferences UI remain outside this slice.
