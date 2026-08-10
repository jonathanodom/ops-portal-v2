@props(['organization'])
@php
    $checks = [
        'Business name' => filled($organization->name),
        'Main phone' => filled($organization->phone),
        'Main email' => filled($organization->email),
        'Mailing address' => filled($organization->address_line_1) && filled($organization->city) && filled($organization->state) && filled($organization->postal_code),
    ];
@endphp
<div {{ $attributes }}>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-xl font-bold">Seller profile readiness</h3>
        <span class="{{ $organization->isBillingProfileComplete() ? 'status-active' : 'status-priority' }}">{{ $organization->isBillingProfileComplete() ? 'Ready' : 'Incomplete' }}</span>
    </div>
    <ul class="mt-4 grid gap-2 sm:grid-cols-2" aria-label="Invoice issue readiness">
        @foreach ($checks as $label => $complete)
            <li class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold">
                <span aria-hidden="true" class="{{ $complete ? 'text-emerald-700' : 'text-amber-700' }}">{{ $complete ? '✓' : '!' }}</span>
                <span>{{ $label }}</span>
                <span class="sr-only">{{ $complete ? 'complete' : 'missing' }}</span>
            </li>
        @endforeach
    </ul>
</div>
