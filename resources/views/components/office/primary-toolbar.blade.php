@props(['title', 'description' => null, 'eyebrow' => null])
<section {{ $attributes->class(['office-primary-toolbar']) }} aria-labelledby="office-primary-toolbar-title">
    <div class="office-primary-toolbar-heading">
        @if($eyebrow)<p class="office-primary-toolbar-eyebrow">{{ $eyebrow }}</p>@endif
        <h1 id="office-primary-toolbar-title" class="office-primary-toolbar-title">{{ $title }}</h1>
        @if($description)<p class="office-primary-toolbar-description">{{ $description }}</p>@endif
    </div>
    @isset($search)<div class="office-primary-toolbar-search">{{ $search }}</div>@endisset
    @isset($viewSwitcher)<div class="office-primary-toolbar-views">{{ $viewSwitcher }}</div>@endisset
    @isset($filters)<div class="office-primary-toolbar-filters">{{ $filters }}</div>@endisset
    @isset($secondaryActions)<div class="office-primary-toolbar-secondary">{{ $secondaryActions }}</div>@endisset
    @isset($primaryAction)<div class="office-primary-toolbar-primary">{{ $primaryAction }}</div>@endisset
    @isset($chips)<div class="office-primary-toolbar-chips">{{ $chips }}</div>@endisset
</section>
