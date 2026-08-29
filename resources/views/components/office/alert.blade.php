@props(['variant' => 'success'])
<div {{ $attributes->class(['office-alert', "office-alert-{$variant}"]) }} role="{{ $variant === 'error' ? 'alert' : 'status' }}">{{ $slot }}</div>
