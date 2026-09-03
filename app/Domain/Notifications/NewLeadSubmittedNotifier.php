<?php

namespace App\Domain\Notifications;

use App\Models\CommercialLeadIntake;
use App\Models\PortalNotificationEvent;

final class NewLeadSubmittedNotifier
{
    public function __construct(private readonly PortalNotificationManager $notifications) {}

    public function notify(CommercialLeadIntake $lead): ?PortalNotificationEvent
    {
        if ($lead->source !== 'website') {
            return null;
        }

        $lead->loadMissing('organization');
        $name = trim($lead->first_name.' '.$lead->last_name);
        $name = $name !== '' ? $name : ($lead->company ?: 'A prospective customer');
        $service = $lead->service_interest ?: 'service';

        return $this->notifications->publish(
            $lead->organization,
            new PortalNotificationPayload(
                eventKey: 'lead.submitted',
                category: 'Leads',
                title: "New Lead — {$name}",
                body: "{$name} submitted a new {$service} inquiry.",
                actionUrl: route('office.leads.show', $lead, false),
                relatedType: $lead->getMorphClass(),
                relatedId: $lead->id,
                priority: 'normal',
                metadata: [
                    'action_label' => 'View Lead',
                    'lead_id' => $lead->id,
                    'source' => $lead->source,
                ],
                defaultChannels: ['in_app', 'email'],
                requiredChannels: ['in_app'],
                idempotencyKey: "lead.submitted:{$lead->id}",
                occurredAt: $lead->received_at,
            ),
            NotificationAudience::capability('opportunities.manage'),
        );
    }
}
