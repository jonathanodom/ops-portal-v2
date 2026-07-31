<x-layouts.field title="Today">
    <p class="text-sm font-bold text-brand-orange">Phase 1</p>
    <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-950">Today</h1>
    <p class="mt-2 text-base leading-7 text-slate-600">{{ now($activeOrganization->timezone)->format('l, F j') }}</p>

    <section class="surface mt-6 overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-lg font-bold text-slate-950">No assigned visits</h2>
        </div>
        <div class="px-5 py-10 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-xl font-bold text-brand-blue" aria-hidden="true">0</div>
            <p class="mt-4 font-bold text-slate-900">Field foundation is ready</p>
            <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-slate-600">Jobs and visits will appear here after the dispatch lifecycle phase is approved and implemented.</p>
        </div>
    </section>

    @if ($activeMembership->hasCapability('customers.view'))
        <a href="{{ route('field.customers.index') }}" class="button-primary mt-5 w-full">Open customer directory</a>
    @endif

    @if ($activeMembership->hasCapability('experience.office.access'))
        <a href="{{ route('office.home') }}" class="button-secondary mt-5 w-full">Open office view</a>
    @endif
</x-layouts.field>
