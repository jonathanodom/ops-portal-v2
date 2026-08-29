@props(['activeCount' => 0, 'label' => 'Filters'])
<details class="office-filter-disclosure">
    <summary class="button-secondary cursor-pointer list-none">
        {{ $label }}
        @if($activeCount > 0)<span class="office-filter-count" aria-label="{{ $activeCount }} active {{ Str::plural('filter', $activeCount) }}">{{ $activeCount }}</span>@endif
    </summary>
    <div class="office-filter-panel">
        {{ $slot }}
    </div>
</details>
