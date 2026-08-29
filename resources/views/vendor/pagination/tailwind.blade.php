@if ($paginator->hasPages())
    <nav aria-label="{{ __('Pagination Navigation') }}" class="flex flex-wrap items-center justify-between gap-4">
        <p class="text-sm text-slate-700">
            {{ __('Showing') }} {{ $paginator->firstItem() ?? 0 }} {{ __('to') }} {{ $paginator->lastItem() ?? 0 }}
            {{ __('of') }} {{ $paginator->total() }} {{ __('results') }}
        </p>

        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span role="link" aria-disabled="true" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-slate-100 px-3 text-sm font-semibold text-slate-600">
                    ← Previous
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="button-secondary px-3">
                    ← Previous
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-hidden="true" class="inline-flex min-h-11 items-center px-2 text-slate-600">{{ $element }}</span>
                @elseif (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span role="link" aria-current="page" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg bg-brand-blue-dark px-3 text-sm font-bold text-white">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="button-secondary px-3">
                    Next →
                </a>
            @else
                <span role="link" aria-disabled="true" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-slate-100 px-3 text-sm font-semibold text-slate-600">
                    Next →
                </span>
            @endif
        </div>
    </nav>
@endif
