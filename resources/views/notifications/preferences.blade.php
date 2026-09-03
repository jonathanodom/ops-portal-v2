@php($officeExperience = $activeMembership->hasCapability('experience.office.access'))

@if($officeExperience)
    <x-layouts.office title="Notification Preferences" width="form">
        @include('notifications.partials.preferences-form')
    </x-layouts.office>
@else
    <x-layouts.field title="Notification Preferences">
        @include('notifications.partials.preferences-form')
    </x-layouts.field>
@endif
