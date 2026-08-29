@props(['title', 'message' => null, 'variant' => 'empty'])
@php
    $role = in_array($variant, ['error', 'permission'], true) ? 'alert' : 'status';
@endphp
<section {{ $attributes->class(['office-state-panel', "office-state-panel-{$variant}"]) }} role="{{ $role }}" @if($variant === 'loading') aria-busy="true" @endif>
    <h2 class="office-state-panel-title">{{ $title }}</h2>
    @if($message)<p class="office-state-panel-message">{{ $message }}</p>@endif
    @isset($actions)<div class="office-state-panel-actions">{{ $actions }}</div>@endisset
</section>
