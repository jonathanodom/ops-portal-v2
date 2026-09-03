const root = document.querySelector('[data-browser-push]');

if (root) {
    const status = root.querySelector('[data-browser-push-status]');
    const enable = root.querySelector('[data-browser-push-enable]');
    const disable = root.querySelector('[data-browser-push-disable]');
    const csrf = root.querySelector('[data-browser-push-csrf]').value;
    let registration;
    let configuration;

    const supported = window.isSecureContext
        && 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;

    const setState = (message, enabled = false, blocked = false) => {
        status.textContent = message;
        enable.hidden = enabled || blocked;
        disable.hidden = !enabled;
        enable.disabled = false;
        disable.disabled = false;
    };

    const request = async (url, options = {}) => {
        const response = await fetch(url, {
            ...options,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                ...(options.headers ?? {}),
            },
        });
        if (!response.ok) throw new Error('request-failed');

        return response;
    };

    const applicationServerKey = (value) => {
        const padding = '='.repeat((4 - value.length % 4) % 4);
        const decoded = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));

        return Uint8Array.from([...decoded].map((character) => character.charCodeAt(0)));
    };

    const persist = (subscription) => request(root.dataset.subscribeUrl, {
        method: 'POST',
        body: JSON.stringify(subscription.toJSON()),
    });

    const initialize = async () => {
        if (!supported) {
            setState('Browser notifications are not supported in this browser.', false, true);
            return;
        }
        if (Notification.permission === 'denied') {
            setState('Browser notifications are blocked in browser settings.', false, true);
            return;
        }

        try {
            configuration = await fetch(root.dataset.configurationUrl, { headers: { Accept: 'application/json' } }).then((response) => {
                if (!response.ok) throw new Error('configuration-failed');
                return response.json();
            });
            if (!configuration.configured) {
                setState('Browser notifications are not configured.', false, true);
                return;
            }
            registration = await navigator.serviceWorker.register('/ops-notifications-sw.js', { scope: '/' });
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                await persist(subscription);
                setState('Browser notifications are enabled on this device.', true);
            } else {
                setState('Browser notifications are off on this device.');
            }
        } catch (_) {
            setState('Browser notification status is unavailable. Try again.');
        }
    };

    enable.addEventListener('click', async () => {
        enable.disabled = true;
        status.textContent = 'Requesting browser permission…';
        try {
            const permission = await Notification.requestPermission();
            if (permission === 'denied') {
                setState('Browser notifications are blocked in browser settings.', false, true);
                return;
            }
            if (permission !== 'granted') {
                setState('Browser notification permission was not granted.');
                return;
            }
            registration ??= await navigator.serviceWorker.register('/ops-notifications-sw.js', { scope: '/' });
            let subscription = await registration.pushManager.getSubscription();
            subscription ??= await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey(configuration.public_key),
            });
            await persist(subscription);
            setState('Browser notifications are enabled on this device.', true);
        } catch (_) {
            setState('Browser notifications could not be enabled. Try again.');
        }
    });

    disable.addEventListener('click', async () => {
        disable.disabled = true;
        status.textContent = 'Disabling browser notifications…';
        try {
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                await request(root.dataset.unsubscribeUrl, {
                    method: 'DELETE',
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });
                await subscription.unsubscribe();
            }
            setState('Browser notifications are off on this device.');
        } catch (_) {
            setState('Browser notifications could not be disabled. Try again.', true);
        }
    });

    initialize();
}
