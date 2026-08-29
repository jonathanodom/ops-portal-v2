@props(['title', 'description' => null])
<section {{ $attributes->class(['office-form-section']) }}>
    <header class="office-form-section-header">
        <h2 class="office-form-section-title">{{ $title }}</h2>
        @if($description)<p class="office-form-section-description">{{ $description }}</p>@endif
    </header>
    <div class="office-form-section-body">{{ $slot }}</div>
</section>
