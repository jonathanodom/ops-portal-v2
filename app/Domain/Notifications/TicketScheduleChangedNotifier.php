<?php

namespace App\Domain\Notifications;

use App\Models\OrganizationMembership;
use App\Models\PortalNotificationEvent;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class TicketScheduleChangedNotifier
{
    public function __construct(private readonly PortalNotificationManager $notifications) {}

    /** @param list<int> $membershipIds */
    public function notify(
        Visit $visit,
        array $membershipIds,
        User $actor,
        int $scheduleEventId,
        string $change,
        ?CarbonInterface $previousStart,
        ?CarbonInterface $previousEnd,
    ): ?PortalNotificationEvent {
        if (! in_array($change, ['scheduled', 'rescheduled', 'unscheduled'], true)) {
            throw new InvalidArgumentException('Unsupported ticket schedule notification change.');
        }

        $visit->loadMissing('serviceTicket.organization');
        $userIds = OrganizationMembership::query()
            ->where('organization_id', $visit->organization_id)
            ->where('status', 'active')
            ->whereIn('id', array_values(array_unique(array_map('intval', $membershipIds))))
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
        $newSchedule = $this->schedule($visit->scheduled_start_at, $visit->scheduled_end_at, $visit->timezone);
        $previousSchedule = $this->schedule($previousStart, $previousEnd, $visit->timezone);
        [$eventKey, $title, $body, $pushTitle, $pushBody] = match ($change) {
            'scheduled' => [
                'ticket.scheduled',
                'Job Scheduled',
                "{$ticket->ticket_number} — {$ticket->title} has been scheduled for {$newSchedule}.",
                'Job Scheduled',
                "{$ticket->ticket_number} has been scheduled. Open Ops Portal to review.",
            ],
            'rescheduled' => [
                'ticket.rescheduled',
                'Job Rescheduled',
                "{$ticket->ticket_number} — {$ticket->title} has been rescheduled.\n\nPrevious: {$previousSchedule}\nNew: {$newSchedule}",
                'Job Rescheduled',
                "{$ticket->ticket_number} has been rescheduled. Open Ops Portal to review.",
            ],
            'unscheduled' => [
                'ticket.unscheduled',
                'Job Schedule Removed',
                "The schedule for {$ticket->ticket_number} has been removed. Open Ops Portal for details.",
                'Job Schedule Removed',
                "The schedule for {$ticket->ticket_number} has been removed. Open Ops Portal for details.",
            ],
        };

        return $this->notifications->publish(
            $ticket->organization,
            new PortalNotificationPayload(
                eventKey: $eventKey,
                category: 'Service Tickets',
                title: $title,
                body: $body,
                actionUrl: route('field.visits.show', $visit, false),
                relatedType: $ticket->getMorphClass(),
                relatedId: $ticket->id,
                actorId: $actor->id,
                metadata: [
                    'action_label' => 'View Ticket',
                    'schedule_event_id' => $scheduleEventId,
                    'ticket_id' => $ticket->id,
                    'visit_id' => $visit->id,
                    'change' => $change,
                    'push_title' => $pushTitle,
                    'push_body' => $pushBody,
                ],
                defaultChannels: ['in_app', 'email', 'push'],
                requiredChannels: ['in_app'],
                idempotencyKey: "{$eventKey}:{$scheduleEventId}",
            ),
            NotificationAudience::users($userIds),
        );
    }

    private function schedule(?CarbonInterface $start, ?CarbonInterface $end, string $timezone): string
    {
        if ($start === null) {
            return 'not scheduled';
        }

        $localStart = $start->copy()->timezone($timezone);
        if ($end === null) {
            return $localStart->format('F j \a\t g:i A T');
        }

        $localEnd = $end->copy()->timezone($timezone);
        if ($localStart->isSameDay($localEnd)) {
            return $localStart->format('F j \a\t g:i A').'–'.$localEnd->format('g:i A T');
        }

        return $localStart->format('F j \a\t g:i A T').'–'.$localEnd->format('F j \a\t g:i A T');
    }
}
