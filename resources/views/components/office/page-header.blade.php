@props(['title', 'description' => null, 'eyebrow' => null])
<header {{ $attributes->class(['office-page-header']) }}>
    <div class="min-w-0">
        @if($eyebrow)<p class="text-xs font-bold uppercase tracking-[0.12em] text-brand-blue">{{ $eyebrow }}</p>@endif
        <h1 class="{{ $eyebrow ? 'mt-1' : '' }} text-3xl font-bold tracking-tight text-slate-950">{{ $title }}</h1>
        @if($description)<p class="mt-1.5 max-w-3xl text-sm text-slate-600">{{ $description }}</p>@endif
    </div>
    @isset($actions)<div class="flex shrink-0 flex-wrap gap-3">{{ $actions }}</div>@endisset
</header>
