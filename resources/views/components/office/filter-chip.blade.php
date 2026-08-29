@props(['label', 'removeUrl'])
<a href="{{ $removeUrl }}" class="office-filter-chip" aria-label="Remove filter: {{ $label }}">
    <span>{{ $label }}</span><span aria-hidden="true">&times;</span>
</a>
