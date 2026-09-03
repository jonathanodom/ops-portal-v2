# Unified Notification Platform — Slice 4

Slice 4 adds standards-based browser Web Push to the normalized notification platform. The existing `lead.submitted` event now permits in-app, email, and push delivery. Push uses a privacy-safe title and body while retaining the authenticated Office Lead deep link.

## Subscription behavior

- An authenticated user explicitly enables notifications from the notification center.
- The browser owns permission prompting and may retain multiple subscriptions per user.
- Registration is organization scoped and idempotent by endpoint hash. The authenticated session determines ownership.
- Disable removes only the current user's matching subscription.
- HTTP 404/410 provider responses permanently disable stale subscriptions; temporary failures follow queue retry behavior.

## Production configuration

Set these values without committing secrets:

```dotenv
WEB_PUSH_VAPID_SUBJECT=mailto:support@newdaytech.net
WEB_PUSH_VAPID_PUBLIC_KEY=
WEB_PUSH_VAPID_PRIVATE_KEY=
```

Generate a VAPID key pair using an approved deployment-secret workflow. Only the public key is returned to authenticated browser code. The private key stays in server configuration.

Deployment must:

1. run additive migrations;
2. install Composer dependencies, including `minishlink/web-push`;
3. build and publish Vite assets;
4. make `/ops-notifications-sw.js` available from the application origin;
5. run the existing database queue worker; and
6. serve staging/production over HTTPS.

Localhost is treated as a secure context by supported browsers, but other development hostnames require HTTPS. Browser and operating-system policies may suppress notifications, and denied permission must be changed by the user in browser settings.

## Deferred

Native mobile push, job/schedule/office event integrations, a complete notification preference matrix, and Slice 5 remain outside this slice.
