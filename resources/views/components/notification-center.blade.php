<div class="relative" data-notification-center data-recent-url="{{ route('notifications.recent') }}" data-count-url="{{ route('notifications.unread-count') }}">
    <input type="hidden" value="{{ csrf_token() }}" data-notification-csrf>
    <button type="button" class="relative inline-flex min-h-11 min-w-11 items-center justify-center border border-slate-300 bg-white text-slate-700 hover:border-brand-blue hover:text-brand-blue focus:outline-none focus:ring-2 focus:ring-brand-blue focus:ring-offset-2" aria-label="Notifications" aria-controls="notification-center-panel" aria-expanded="false" data-notification-toggle>
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0a3 3 0 0 1-6 0" /></svg>
        <span class="absolute -right-1 -top-1 hidden min-h-5 min-w-5 items-center justify-center bg-red-600 px-1 text-[11px] font-bold leading-none text-white" aria-label="0 unread notifications" data-notification-badge></span>
    </button>

    <section id="notification-center-panel" class="fixed inset-x-2 top-16 z-50 hidden max-h-[min(36rem,calc(100dvh-5rem))] overflow-hidden border border-slate-300 bg-white shadow-lg sm:absolute sm:inset-x-auto sm:right-0 sm:top-[calc(100%+.5rem)] sm:w-96" aria-label="Recent notifications" data-notification-panel>
        <div class="flex min-h-12 items-center justify-between gap-3 border-b border-slate-200 px-4 py-2">
            <h2 class="font-bold text-slate-950">Notifications</h2>
            <form method="POST" action="{{ route('notifications.read-all') }}" data-notification-read-all-form>
                @csrf
                <button class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-brand-blue hover:underline">Mark all read</button>
            </form>
        </div>
        <p class="px-4 py-5 text-sm text-slate-600" role="status" aria-live="polite" data-notification-status>Loading notifications…</p>
        <div class="max-h-[26rem] divide-y divide-slate-200 overflow-y-auto" data-notification-list></div>
        <section class="border-t border-slate-200 px-4 py-3" aria-labelledby="browser-push-heading" data-browser-push
                 data-configuration-url="{{ route('notifications.push.configuration') }}"
                 data-subscribe-url="{{ route('notifications.push.subscriptions.store') }}"
                 data-unsubscribe-url="{{ route('notifications.push.subscriptions.destroy') }}">
            <input type="hidden" value="{{ csrf_token() }}" data-browser-push-csrf>
            <h3 id="browser-push-heading" class="text-sm font-bold text-slate-950">Browser notifications</h3>
            <p class="mt-1 text-xs text-slate-600" role="status" aria-live="polite" data-browser-push-status>Checking browser support…</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <button type="button" class="button-secondary min-h-11 px-3 text-xs" data-browser-push-enable>Enable browser notifications</button>
                <button type="button" class="button-secondary min-h-11 px-3 text-xs" data-browser-push-disable hidden>Disable browser notifications</button>
            </div>
        </section>
        <div class="grid grid-cols-2 border-t border-slate-200">
            <a href="{{ route('notifications.index') }}" class="flex min-h-12 items-center justify-center border-r border-slate-200 px-3 text-center text-sm font-bold text-brand-blue hover:bg-blue-50">View All Notifications</a>
            <a href="{{ route('notifications.preferences.edit') }}" class="flex min-h-12 items-center justify-center px-3 text-center text-sm font-bold text-brand-blue hover:bg-blue-50">Preferences</a>
        </div>
    </section>
</div>
