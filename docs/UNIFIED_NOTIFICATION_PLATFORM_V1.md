# Unified Notification Platform V1

Ops Portal records every supported operational notification in the in-app center. Users may independently disable email or browser delivery for New Leads, Job Assignments, Schedule Changes, Return Visit Updates, and Office Updates. Missing preference rows use the event defaults; no preference backfill is required.

## Production configuration

- Configure Laravel mail delivery (`MAIL_MAILER`, provider host/credentials, and `MAIL_FROM_*`) and keep a queue worker running for email jobs.
- Set `WEB_PUSH_VAPID_SUBJECT`, `WEB_PUSH_VAPID_PUBLIC_KEY`, and `WEB_PUSH_VAPID_PRIVATE_KEY`; never expose the private key.
- Serve the portal over HTTPS so the service worker and Push API are available.
- Keep `QUEUE_CONNECTION=database` (or the approved production queue) and run `php artisan queue:work` under the host's persistent process manager (Supervisor, systemd, or the hosting platform's worker facility). Monitor failed jobs. Email and temporary push failures retry; permanently expired push endpoints are disabled.

Notification event idempotency prevents duplicate logical events. Recipient rows remain organization- and user-scoped. In-app delivery is required for supported events and cannot be disabled through preferences. Each browser subscription is independent; disabling browser notifications removes only the current browser subscription.

## Deferred work

SMS, native mobile push, digest schedules, escalation rules, administrator-managed user preferences, and external notification providers are outside V1.
