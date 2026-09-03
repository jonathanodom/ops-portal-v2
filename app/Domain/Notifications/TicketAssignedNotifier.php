<?php

namespace App\Domain\Notifications;

use App\Models\OrganizationMembership;
use App\Models\PortalNotificationEvent;
use App\Models\User;
use App\Models\Visit;

final class TicketAssignedNotifier
{
    public function __construct(private readonly PortalNotificationManager $notifications) {}

    /** @param list<int> $membershipIds */
    public function notify(Visit $visit, array $membershipIds, User $actor, int $assignmentEventId): ?PortalNotificationEvent
    {
        $visit->loadMissing('serviceTicket.organization');
        $userIds = OrganizationMembership::query()
            ->where('organization_id', $visit->organization_id)
            ->where('status', 'active')
            ->whereIn('id', $membershipIds)
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($userIds === []) {
            return null;
        }

        $ticket = $visit->serviceTicket;

        return $this->notifications->publish(
            $ticket->organization,
            new PortalNotificationPayload(
                eventKey: 'ticket.assigned',
                category: 'Service Tickets',
                title: 'New Job Assignment — '.$ticket->ticket_number,
                body: "You have been assigned to {$ticket->ticket_number} — {$ticket->title}.",
                actionUrl: route('field.visits.show', $visit, false),
                relatedType: $ticket->getMorphClass(),
                relatedId: $ticket->id,
                actorId: $actor->id,
                metadata: [
                    'action_label' => 'View Ticket',
                    'assignment_event_id' => $assignmentEventId,
                    'ticket_id' => $ticket->id,
                    'visit_id' => $visit->id,
                    'push_title' => 'New Job Assignment',
                    'push_body' => "You have been assigned to {$ticket->ticket_number}. Open Ops Portal to review.",
                ],
                defaultChannels: ['in_app', 'email', 'push'],
                requiredChannels: ['in_app'],
                idempotencyKey: "ticket.assigned:{$assignmentEventId}",
            ),
            NotificationAudience::users($userIds),
        );
    }
}
