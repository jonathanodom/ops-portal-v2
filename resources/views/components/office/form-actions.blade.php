@props(['message' => null])
<footer {{ $attributes->class(['office-form-actions']) }}>
    @if($message)
        <p class="office-form-actions-message">{{ $message }}</p>
    @endif
    <div class="office-form-actions-controls">{{ $slot }}</div>
</footer>
