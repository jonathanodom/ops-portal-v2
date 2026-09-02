const center = document.querySelector('[data-notification-center]');

if (center) {
    const toggle = center.querySelector('[data-notification-toggle]');
    const panel = center.querySelector('[data-notification-panel]');
    const badge = center.querySelector('[data-notification-badge]');
    const status = center.querySelector('[data-notification-status]');
    const list = center.querySelector('[data-notification-list]');
    const readAllForm = center.querySelector('[data-notification-read-all-form]');
    const csrf = center.querySelector('[data-notification-csrf]')?.value;
    let loaded = false;

    const setUnreadCount = (count) => {
        const total = Math.max(0, Number(count) || 0);
        badge.textContent = total > 99 ? '99+' : String(total);
        badge.setAttribute('aria-label', `${total} unread notification${total === 1 ? '' : 's'}`);
        badge.classList.toggle('hidden', total === 0);
        badge.classList.toggle('inline-flex', total > 0);
    };

    const request = async (url, options = {}) => {
        const response = await fetch(url, {
            headers: { Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
            ...options,
        });
        if (!response.ok) throw new Error('notification-request-failed');
        return response.json();
    };

    const openNotification = async (notification, button) => {
        button.disabled = true;
        try {
            const body = await request(notification.open_url, { method: 'POST' });
            window.location.assign(body.destination);
        } catch {
            button.disabled = false;
            status.hidden = false;
            status.textContent = 'Unable to open this notification. Try again.';
        }
    };

    const render = (notifications) => {
        list.replaceChildren();
        notifications.forEach((notification) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `block min-h-11 w-full px-4 py-3 text-left hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-brand-blue ${notification.unread ? 'border-l-4 border-l-brand-blue bg-blue-50/50' : ''}`;
            const heading = document.createElement('span');
            heading.className = 'flex items-center justify-between gap-3 font-bold text-slate-950';
            heading.textContent = notification.title;
            if (notification.unread) {
                const unread = document.createElement('span');
                unread.className = 'shrink-0 border border-brand-blue px-1.5 py-0.5 text-[11px] text-brand-blue';
                unread.textContent = 'Unread';
                heading.append(unread);
            }
            const message = document.createElement('span');
            message.className = 'mt-1 block text-sm text-slate-700';
            message.textContent = notification.message;
            const time = document.createElement('span');
            time.className = 'mt-1 block text-xs text-slate-500';
            time.textContent = notification.occurred_human;
            button.append(heading, message, time);
            button.addEventListener('click', () => openNotification(notification, button));
            list.append(button);
        });
        status.hidden = notifications.length > 0;
        status.textContent = notifications.length ? '' : 'No notifications yet.';
    };

    const load = async () => {
        status.hidden = false;
        status.textContent = 'Loading notifications…';
        try {
            const [recent, count] = await Promise.all([
                request(center.dataset.recentUrl),
                request(center.dataset.countUrl),
            ]);
            render(recent.notifications ?? []);
            setUnreadCount(count.unread_count);
            loaded = true;
        } catch {
            status.hidden = false;
            status.textContent = 'Notifications are temporarily unavailable. The rest of the portal remains available.';
        }
    };

    const close = (restoreFocus = false) => {
        panel.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
        if (restoreFocus) toggle.focus();
    };

    toggle.addEventListener('click', () => {
        const opening = panel.classList.contains('hidden');
        if (!opening) return close();
        panel.classList.remove('hidden');
        toggle.setAttribute('aria-expanded', 'true');
        if (!loaded) load();
    });
    document.addEventListener('click', (event) => {
        if (!center.contains(event.target)) close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.classList.contains('hidden')) close(true);
    });
    readAllForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await request(readAllForm.action, { method: 'POST' });
            setUnreadCount(0);
            loaded = false;
            await load();
        } catch {
            status.hidden = false;
            status.textContent = 'Unable to mark notifications as read. Try again.';
        }
    });

    request(center.dataset.countUrl).then((body) => setUnreadCount(body.unread_count)).catch(() => setUnreadCount(0));
}
