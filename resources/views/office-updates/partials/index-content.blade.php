<div class="space-y-5">
    @if(session('status'))<x-office.alert>{{ session('status') }}</x-office.alert>@endif
    <div class="flex flex-col gap-3 border-b border-slate-300 pb-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-blue">Staff</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Office Updates</h1>
            <p class="mt-1 text-sm text-slate-600">Published staff announcements remain available after their notifications are read.</p>
        </div>
        @can('publish', [App\Models\OfficeUpdate::class, $activeOrganization])
            <a class="button-primary" href="{{ route('office-updates.create') }}">New Office Update</a>
        @endcan
    </div>

    <div class="divide-y divide-slate-200 border border-slate-300 bg-white">
        @forelse($updates as $update)
            <article class="p-4 sm:p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h2 class="font-bold text-slate-950"><a class="hover:text-brand-blue focus:outline-none focus:ring-2 focus:ring-brand-blue" href="{{ route('office-updates.show', $update) }}">{{ $update->title }}</a></h2>
                        <p class="mt-2 line-clamp-2 whitespace-pre-line text-sm text-slate-700">{{ $update->body }}</p>
                        <p class="mt-2 text-xs text-slate-500">{{ str($update->audience_type)->replace('_', ' ')->title() }} · {{ $update->publishedBy->name }} · <x-local-time :value="$update->published_at" :timezone="$activeOrganization->timezone" format="M j, Y g:i A" /></p>
                    </div>
                    <a class="button-secondary shrink-0" href="{{ route('office-updates.show', $update) }}">View update</a>
                </div>
            </article>
        @empty
            <div class="p-6"><x-office.state-panel title="No Office Updates" message="Published staff announcements will appear here." /></div>
        @endforelse
    </div>

    {{ $updates->links() }}
</div>
