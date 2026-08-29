@props(['title', 'backHref', 'backLabel', 'description' => null])
<header {{ $attributes->class(['office-record-header']) }}>
    <a href="{{ $backHref }}" class="office-record-back">&larr; {{ $backLabel }}</a>
    <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight text-slate-950">{{ $title }}</h1>
                @isset($badges)<div class="flex flex-wrap items-center gap-2">{{ $badges }}</div>@endisset
            </div>
            @if($description)<p class="mt-1 text-sm text-slate-600">{{ $description }}</p>@endif
        </div>
        @isset($actions)<div class="flex shrink-0 flex-wrap gap-2">{{ $actions }}</div>@endisset
    </div>
</header>
