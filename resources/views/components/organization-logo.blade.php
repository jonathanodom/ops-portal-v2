@props(['variant' => 'full', 'class' => ''])
@php
    $asset = $variant === 'mark' ? ($activeOrganization->currentMarkLogo ?: $activeOrganization->currentFullLogo) : $activeOrganization->currentFullLogo;
    $source = $asset ? route('organization.brand.asset', ['variant' => $variant, 'v' => $asset->updated_at?->timestamp]) : asset('images/newday-logo.png');
@endphp
<img src="{{ $source }}" alt="{{ $activeOrganization->name }}" {{ $attributes->merge(['class' => $class]) }}>
