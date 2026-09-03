@php($officeExperience = $activeMembership->hasCapability('experience.office.access'))

@if($officeExperience)
    <x-layouts.office :title="$update->title" width="detail">
        @include('office-updates.partials.show-content')
    </x-layouts.office>
@else
    <x-layouts.field :title="$update->title">
        @include('office-updates.partials.show-content')
    </x-layouts.field>
@endif
