<x-layouts.office title="Foundation overview">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-bold text-brand-orange">Phase 0</p>
            <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-950">Operations foundation</h1>
            <p class="mt-2 max-w-2xl text-base leading-7 text-slate-600">The secure office workspace is ready. Customer and field-service modules remain intentionally gated until their phase is approved.</p>
        </div>
        <span class="inline-flex w-fit rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-bold text-emerald-800">Foundation active</span>
    </div>

    <section class="mt-8 grid gap-4 md:grid-cols-3" aria-label="Foundation status">
        <article class="surface p-5">
            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Identity</p>
            <h2 class="mt-3 text-lg font-bold text-slate-950">Staff access secured</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">Portal accounts, active memberships, and server-side capability checks are enabled.</p>
        </article>
        <article class="surface p-5">
            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Experience</p>
            <h2 class="mt-3 text-lg font-bold text-slate-950">Office and field separated</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">Two focused interfaces share one identity and authorization foundation.</p>
        </article>
        <article class="surface border-l-4 border-l-brand-orange p-5">
            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Next gate</p>
            <h2 class="mt-3 text-lg font-bold text-slate-950">Customer foundation</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">Customer and service-location records begin only after this phase is reviewed.</p>
        </article>
    </section>

    <section class="surface mt-6 p-5 sm:p-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold text-slate-950">No operational queues yet</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">This is intentional. Unfinished modules are not presented as shipped features.</p>
            </div>
            <span class="hidden h-10 w-10 items-center justify-center rounded-full bg-blue-50 text-xl font-bold text-brand-blue sm:flex" aria-hidden="true">0</span>
        </div>
    </section>
</x-layouts.office>
