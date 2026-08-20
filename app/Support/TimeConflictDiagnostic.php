<?php

namespace App\Support;

use App\Models\Visit;
use App\Models\VisitTimeEntry;

class TimeConflictDiagnostic
{
    public function message(VisitTimeEntry $conflict, Visit $contextVisit): string
    {
        if ((int) $conflict->organization_id !== (int) $contextVisit->organization_id) {
            return 'Time conflict: this user already has another time entry during this period. Details are unavailable because the entry belongs to another organization.';
        }

        $conflict->loadMissing([
            'user:id,name',
            'visit' => fn ($query) => $query->select(['id', 'organization_id', 'service_ticket_id', 'ticket_visit_number', 'timezone']),
            'visit.serviceTicket:id,organization_id,ticket_number',
            'visit.serviceTicket.organization:id,timezone',
        ]);

        $visit = $conflict->visit;
        $ticket = $visit?->serviceTicket;
        $timezone = $this->timezone($visit?->timezone, $ticket?->organization?->timezone);
        $start = $conflict->started_at?->copy()->timezone($timezone);
        $end = $conflict->ended_at?->copy()->timezone($timezone);
        $user = $conflict->user?->name ?: 'This user';
        $category = $this->categoryLabel($conflict->category);
        $source = $this->sourceLabel($conflict->source);
        $ticketLabel = filled($ticket?->ticket_number)
            ? 'Service Ticket '.$ticket->ticket_number
            : 'an unavailable Service Ticket';
        $visitLabel = $visit?->ticket_visit_number
            ? $visit->displayNumber()
            : 'Visit unavailable';

        if (! $end) {
            $entryLabel = $source === 'Timer'
                ? "an active {$category} timer"
                : "an active {$source} {$category} entry";
            $started = $start
                ? $start->format('M j').' at '.$start->format('g:i A')
                : 'an unavailable time';

            return "Time conflict: {$user} has {$entryLabel} that started {$started} on {$ticketLabel}, {$visitLabel} and has no end time.";
        }

        $interval = $start
            ? $start->format('M j, g:i A').'–'.($start->isSameDay($end) ? $end->format('g:i A') : $end->format('M j, g:i A'))
            : 'an unavailable interval ending '.$end->format('M j, g:i A');

        return "Time conflict: {$user} already has a {$source} {$category} entry from {$interval} on {$ticketLabel}, {$visitLabel}.";
    }

    private function timezone(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate && in_array($candidate, timezone_identifiers_list(), true)) {
                return $candidate;
            }
        }

        return config('app.timezone', 'UTC');
    }

    private function categoryLabel(?string $category): string
    {
        return match ($category) {
            'travel' => 'Travel',
            'on_site' => 'On-site',
            'other' => 'Other',
            default => 'Time',
        };
    }

    private function sourceLabel(?string $source): string
    {
        return match ($source) {
            'manual' => 'Manual',
            'system', 'system_auto' => 'System',
            default => 'Timer',
        };
    }
}
