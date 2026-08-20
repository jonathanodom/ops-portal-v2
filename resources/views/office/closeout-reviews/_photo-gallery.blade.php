@php
    $previewableMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
@endphp

<div class="mt-4" data-review-photo-gallery>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="font-bold text-slate-950">Photos</h3>
        <p class="text-sm text-slate-600">{{ $activeMedia->count() }} active across {{ $versions->count() }} version(s)</p>
    </div>

    @if($activeMedia->isEmpty())
        <p class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">No active photos recorded.</p>
    @else
        <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-4" data-review-photo-grid>
            @foreach($activeMedia as $index => $item)
                @php
                    $media = $item['media'];
                    $version = $item['version'];
                    $previewable = in_array($media->mime_type, $previewableMimeTypes, true);
                    $mediaUrl = route('field.media.show', $media);
                    $category = ucfirst(str_replace('_', ' ', $media->category));
                    $fallbackLabel = in_array($media->mime_type, ['image/heic', 'image/heif'], true) ? 'HEIC photo' : 'Photo';
                @endphp
                <article class="min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-white" data-review-photo-item data-index="{{ $index }}" data-src="{{ $mediaUrl }}" data-category="{{ $category }}" data-version="v{{ $version }}" data-caption="{{ $media->caption ?? '' }}" data-previewable="{{ $previewable ? 'true' : 'false' }}">
                    @if($previewable)
                        <button type="button" class="group block min-h-11 w-full text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-blue" data-review-photo-open aria-label="Open {{ strtolower($category) }} photo from closeout version {{ $version }}">
                            <span class="relative block aspect-[4/3] overflow-hidden bg-slate-100">
                                <img class="h-full w-full object-cover transition-opacity group-hover:opacity-90" src="{{ $mediaUrl }}" alt="{{ $category }} evidence, closeout version {{ $version }}" loading="lazy" decoding="async" data-review-photo-thumbnail>
                                <span class="absolute inset-0 hidden items-center justify-center bg-slate-100 p-3 text-center text-sm font-semibold text-slate-700" data-review-photo-thumbnail-fallback>Preview unavailable</span>
                            </span>
                        </button>
                    @else
                        <div class="flex aspect-[4/3] flex-col items-center justify-center bg-slate-100 p-3 text-center">
                            <p class="font-bold text-slate-800">{{ $fallbackLabel }}</p>
                            <p class="mt-1 text-xs text-slate-600">Preview unavailable in this browser</p>
                            <a class="mt-2 inline-flex min-h-11 items-center font-bold text-brand-blue underline" href="{{ $mediaUrl }}" target="_blank" rel="noopener">Open original</a>
                        </div>
                    @endif
                    <div class="p-3">
                        <p class="text-sm font-bold text-slate-950">{{ $category }} · v{{ $version }}</p>
                        @if(filled($media->caption))<p class="mt-1 break-words text-sm text-slate-600">{{ $media->caption }}</p>@endif
                    </div>
                </article>
            @endforeach
        </div>

        <dialog class="m-auto h-[100dvh] max-h-none w-screen max-w-none overflow-hidden bg-transparent p-0 backdrop:bg-slate-950/80 sm:h-[92vh] sm:w-[96vw] sm:rounded-xl" data-review-photo-dialog aria-labelledby="review-photo-dialog-title">
            <div class="flex h-full min-h-0 flex-col bg-slate-950 text-white">
                <header class="flex min-h-14 shrink-0 items-center justify-between gap-3 border-b border-white/20 px-4 py-2">
                    <div class="min-w-0">
                        <h3 id="review-photo-dialog-title" class="truncate font-bold">Photo evidence</h3>
                        <p class="text-sm text-slate-300" data-review-photo-position></p>
                    </div>
                    <button type="button" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-white/30 px-3 font-bold hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" data-review-photo-close aria-label="Close photo viewer">Close</button>
                </header>
                <div class="relative flex min-h-0 flex-1 items-center justify-center p-4">
                    <img class="max-h-full max-w-full object-contain" alt="" data-review-photo-image>
                    <div class="hidden max-w-md rounded-lg border border-white/30 bg-slate-900 p-6 text-center" data-review-photo-full-fallback>
                        <p class="font-bold">Preview unavailable</p>
                        <a class="mt-3 inline-flex min-h-11 items-center font-bold text-blue-300 underline" href="#" target="_blank" rel="noopener" data-review-photo-original>Open original</a>
                    </div>
                </div>
                <footer class="shrink-0 border-t border-white/20 bg-slate-950 px-4 py-3 pb-[max(.75rem,env(safe-area-inset-bottom))]">
                    <div class="mx-auto flex max-w-5xl items-center justify-between gap-3">
                        <button type="button" class="inline-flex min-h-11 items-center rounded-lg border border-white/30 px-4 font-bold hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" data-review-photo-previous>← Previous</button>
                        <div class="min-w-0 text-center text-sm">
                            <p class="font-bold" data-review-photo-meta></p>
                            <p class="mt-1 break-words text-slate-300" data-review-photo-caption></p>
                        </div>
                        <button type="button" class="inline-flex min-h-11 items-center rounded-lg border border-white/30 px-4 font-bold hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" data-review-photo-next>Next →</button>
                    </div>
                </footer>
            </div>
        </dialog>
    @endif
</div>
