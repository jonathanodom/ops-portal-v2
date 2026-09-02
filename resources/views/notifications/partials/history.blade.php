<div class="space-y-4">
    @if(session('status'))<x-office.alert type="success">{{ session('status') }}</x-office.alert>@endif
    <div class="flex flex-col gap-3 border-b border-slate-300 pb-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-blue">Account</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Notifications</h1>
            <p class="mt-1 text-sm text-slate-600">Operational updates delivered to your account.</p>
        </div>
        <form method="POST" action="{{ route('notifications.read-all') }}">
            @csrf
            <button class="button-secondary w-full sm:w-auto">Mark all read</button>
        </form>
    </div>

    <div class="divide-y divide-slate-200 border border-slate-300 bg-white">
        @forelse($notifications as $notification)
            <article class="p-4 {{ $notification->read_at ? '' : 'border-l-4 border-l-brand-blue bg-blue-50/50' }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-bold text-slate-950">{{ $notification->event->title }}</h2>
                            @if(!$notification->read_at)<span class="border border-brand-blue px-2 py-0.5 text-xs font-bold text-brand-blue">Unread</span>@endif
                            <span class="text-xs font-semibold text-slate-500">{{ $notification->event->category }}</span>
                        </div>
                        <p class="mt-2 text-sm text-slate-700">{{ $notification->event->body }}</p>
                        <p class="mt-2 text-xs text-slate-500"><x-local-time :value="$notification->event->occurred_at" :timezone="$activeOrganization->timezone" format="M j, Y g:i A" /></p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if(!$notification->read_at)
                            <form method="POST" action="{{ route('notifications.read', $notification) }}">@csrf<button class="button-secondary">Mark read</button></form>
                        @endif
                        <form method="POST" action="{{ route('notifications.open', $notification) }}">@csrf<button class="button-primary">Open</button></form>
                    </div>
                </div>
            </article>
        @empty
            <div class="p-6"><x-office.state-panel title="No notifications yet" message="New operational updates will appear here." /></div>
        @endforelse
    </div>

    {{ $notifications->links() }}
</div>
