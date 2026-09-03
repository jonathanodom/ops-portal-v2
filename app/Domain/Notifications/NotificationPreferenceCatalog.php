<?php

namespace App\Domain\Notifications;

final class NotificationPreferenceCatalog
{
    /** @return array<string, array{label: string, description: string, event_keys: list<string>}> */
    public function categories(): array
    {
        return [
            'new_leads' => [
                'label' => 'New Leads',
                'description' => 'New website and manually entered lead activity.',
                'event_keys' => ['lead.submitted'],
            ],
            'job_assignments' => [
                'label' => 'Job Assignments',
                'description' => 'Service Ticket Visit assignments that involve you.',
                'event_keys' => ['ticket.assigned'],
            ],
            'schedule_changes' => [
                'label' => 'Schedule Changes',
                'description' => 'Visits that are scheduled, rescheduled, or unscheduled.',
                'event_keys' => ['ticket.scheduled', 'ticket.rescheduled', 'ticket.unscheduled'],
            ],
            'return_visit_updates' => [
                'label' => 'Return Visit Updates',
                'description' => 'Follow-up Service Tickets created for return work.',
                'event_keys' => ['return_followup.created'],
            ],
            'office_updates' => [
                'label' => 'Office Updates',
                'description' => 'Staff announcements published through Ops Portal.',
                'event_keys' => ['office_update.published'],
            ],
        ];
    }
}
