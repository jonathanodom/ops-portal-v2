# Unified Notification Platform — Slice 1

Slice 1 establishes an organization-scoped notification domain. It does not send email or browser push and does not expose notification UI.

## V1 parity decisions

- **Reuse concept:** durable notification records, recipient-specific read state, channel preferences, and normalized action links.
- **Adapt for V2:** typed payloads, active organization membership targeting, role/capability overrides, organization-scoped preferences, and idempotent publication.
- **Do not port:** V1 global role queries, singleton mixed-purpose settings, synchronous mail delivery, customer-only push subscriptions, service worker, bell/dropdown UI, and direct notification calls from controllers.

## Domain contract

`PortalNotificationPayload` validates and normalizes the event key, category, title, body, action, related subject, actor, priority, safe metadata, channel defaults, required channels, occurrence time, and optional idempotency key. Canonical payloads receive a SHA-256 fingerprint.

`NotificationAudience` targets explicit users, a role, or a capability. `EloquentNotificationRecipientResolver` includes only active users with active memberships in the event organization and honors capability overrides.

`NotificationChannelSelector` applies wildcard and event-specific user preferences. A required channel cannot be disabled by a preference.

`PortalNotificationPublisher` validates actor and related-record organization ownership, snapshots the normalized audience, persists the event and recipient projections transactionally, and safely replays only an identical idempotent publication.

## Persistence

- `portal_notification_events`: normalized immutable event payload and safe provenance.
- `portal_notification_recipients`: organization/user delivery projection, selected channels, and future in-app read state.
- `portal_notification_preferences`: nullable wildcard or event-specific channel overrides.

## Deferred slices

Delivery jobs, email, browser push, subscriptions, service worker support, notification bell/UI, office updates, and lead/ticket event integrations remain deferred.
