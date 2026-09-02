@php($officeExperience = $activeMembership->hasCapability('experience.office.access'))

@if($officeExperience)
    <x-layouts.office title="Notifications" width="detail">
        @include('notifications.partials.history')
    </x-layouts.office>
@else
    <x-layouts.field title="Notifications">
        @include('notifications.partials.history')
    </x-layouts.field>
@endif
