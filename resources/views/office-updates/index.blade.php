@php($officeExperience = $activeMembership->hasCapability('experience.office.access'))

@if($officeExperience)
    <x-layouts.office title="Office Updates" width="detail">
        @include('office-updates.partials.index-content')
    </x-layouts.office>
@else
    <x-layouts.field title="Office Updates">
        @include('office-updates.partials.index-content')
    </x-layouts.field>
@endif
