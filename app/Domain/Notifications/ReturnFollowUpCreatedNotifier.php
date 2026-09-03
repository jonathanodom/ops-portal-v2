<?php

namespace App\Domain\Notifications;

use App\Models\Closeout;
use App\Models\PortalNotificationEvent;
use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Support\Str;

final class ReturnFollowUpCreatedNotifier
{
    public function __construct(private readonly PortalNotificationManager $notifications) {}

    public function notify(ServiceTicket $followUp, Closeout $closeout, User $actor, int $creationEventId): PortalNotificationEvent
    {
        $followUp->loadMissing('organization', 'returnFollowUpSourceTicket');
        $source = $followUp->returnFollowUpSourceTicket;
        $details = collect([
            "A return follow-up {$followUp->ticket_number} was created for {$source->ticket_number} — {$source->title}.",
            filled($closeout->return_reason) ? 'Return Reason: '.Str::limit($closeout->return_reason, 500) : null,
            filled($closeout->unfinished_work) ? 'Unfinished Work: '.Str::limit($closeout->unfinished_work, 500) : null,
            filled($closeout->needed_equipment) ? 'Needed Parts / Equipment: '.Str::limit($closeout->needed_equipment, 500) : null,
        ])->filter()->implode("\n\n");

        return $this->notifications->publish(
            $followUp->organization,
            new PortalNotificationPayload(
                eventKey: 'return_followup.created',
                category: 'Service Tickets',
                title: 'Return Visit Required',
                body: $details,
                actionUrl: route('office.service-tickets.show', $followUp, false),
                relatedType: $followUp->getMorphClass(),
                relatedId: $followUp->id,
                actorId: $actor->id,
                priority: 'high',
                metadata: [
                    'action_label' => 'Review Follow-Up',
                    'creation_event_id' => $creationEventId,
                    'source_ticket_id' => $source->id,
                    'follow_up_ticket_id' => $followUp->id,
                    'source_closeout_id' => $closeout->id,
                    'push_title' => 'Return Visit Required',
                    'push_body' => 'A return follow-up needs review in Ops Portal.',
                ],
                defaultChannels: ['in_app', 'email', 'push'],
                requiredChannels: ['in_app'],
                idempotencyKey: "return_followup.created:{$followUp->id}",
            ),
            NotificationAudience::capability('dispatch.manage'),
        );
    }
}
