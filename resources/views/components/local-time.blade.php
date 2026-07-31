@props([
    'value',
    'timezone',
    'format' => 'M j, Y g:i A T',
])

@if ($value)
    @php($localValue = $value->copy()->timezone($timezone))
    <time datetime="{{ $value->copy()->utc()->toIso8601String() }}" title="{{ $timezone }}">{{ $localValue->format($format) }}</time>
@endif
