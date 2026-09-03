self.addEventListener('push', (event) => {
    let data = {
        title: 'NewDay Tech Ops',
        body: 'A new notification is available in Ops Portal.',
        url: '/notifications',
    };

    try {
        if (event.data) data = { ...data, ...event.data.json() };
    } catch (_) {
        // Keep the privacy-safe fallback notification when a payload is invalid.
    }

    let url = '/notifications';
    try {
        const candidate = new URL(data.url, self.location.origin);
        if (candidate.origin === self.location.origin) url = `${candidate.pathname}${candidate.search}${candidate.hash}`;
    } catch (_) {
        // Keep the safe internal fallback.
    }

    event.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        data: { url },
        tag: data.notificationId ? `ops-notification-${data.notificationId}` : undefined,
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/notifications', self.location.origin).href;

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const matching = windows.find((client) => client.url === target);
        if (matching) return matching.focus();
        const existing = windows.find((client) => new URL(client.url).origin === self.location.origin);
        if (existing) return existing.navigate(target).then((client) => client?.focus());

        return self.clients.openWindow(target);
    }));
});
