@props(['items'])
<nav class="office-detail-nav" aria-label="On this page">
    @foreach($items as $anchor => $label)
        <a href="#{{ $anchor }}" class="office-detail-nav-link">{{ $label }}</a>
    @endforeach
</nav>
