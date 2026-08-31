<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DispatchSchedule
{
    /**
     * @param  array{assignee?: string|int|null, status?: string|null, priority?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function forDispatch(Organization $organization, CarbonImmutable $date, CarbonImmutable $calendarMonth, array $filters): array
    {
        $dayStart = $date->startOfDay();
        $stripEnd = $dayStart->addDays(5);
        $calendarStart = $calendarMonth->startOfMonth()->startOfWeek(CarbonInterface::SUNDAY);
        $calendarEnd = $calendarMonth->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY)->addDay()->startOfDay();

        $calendarVisits = $this->scheduledQuery($organization, $filters)
            ->where('scheduled_start_at', '>=', $calendarStart->utc())
            ->where('scheduled_start_at', '<', $calendarEnd->utc())
            ->orderBy('scheduled_start_at')
            ->get();
        $calendarVisitsByDate = $calendarVisits->groupBy(
            fn (Visit $visit): string => $visit->scheduled_start_at->copy()->timezone($organization->timezone)->format('Y-m-d')
        );

        $dayIsInCalendar = $dayStart->greaterThanOrEqualTo($calendarStart) && $dayStart->lessThan($calendarEnd);
        $dayVisits = $dayIsInCalendar
            ? $calendarVisitsByDate->get($dayStart->format('Y-m-d'), collect())->values()
            : $this->scheduledQuery($organization, $filters)
                ->where('scheduled_start_at', '>=', $dayStart->utc())
                ->where('scheduled_start_at', '<', $dayStart->addDay()->utc())
                ->orderBy('scheduled_start_at')
                ->get();

        $stripIsInCalendar = $dayStart->greaterThanOrEqualTo($calendarStart) && $stripEnd->lessThanOrEqualTo($calendarEnd);
        $stripCounts = $stripIsInCalendar
            ? $calendarVisits
                ->filter(function (Visit $visit) use ($dayStart, $stripEnd, $organization): bool {
                    $start = $visit->scheduled_start_at->copy()->timezone($organization->timezone);

                    return $start->greaterThanOrEqualTo($dayStart) && $start->lessThan($stripEnd);
                })
                ->map(fn (Visit $visit): string => $visit->scheduled_start_at->copy()->timezone($organization->timezone)->format('Y-m-d'))
                ->countBy()
            : $this->filteredQuery($organization, $filters)
                ->where('scheduled_start_at', '>=', $dayStart->utc())
                ->where('scheduled_start_at', '<', $stripEnd->utc())
                ->pluck('scheduled_start_at')
                ->map(fn ($value): string => CarbonImmutable::parse($value)->timezone($organization->timezone)->format('Y-m-d'))
                ->countBy();

        $strip = collect(range(0, 4))->map(function (int $offset) use ($dayStart, $stripCounts): array {
            $stripDate = $dayStart->addDays($offset);

            return [
                'date' => $stripDate,
                'count' => $stripCounts->get($stripDate->format('Y-m-d'), 0),
            ];
        });

        $calendarDays = collect(range(0, $calendarStart->diffInDays($calendarEnd) - 1))
            ->map(function (int $offset) use ($calendarStart, $calendarMonth, $calendarVisitsByDate): array {
                $calendarDate = $calendarStart->addDays($offset);
                /** @var Collection<int, Visit> $visits */
                $visits = $calendarVisitsByDate->get($calendarDate->format('Y-m-d'), collect());

                return [
                    'date' => $calendarDate,
                    'in_month' => $calendarDate->month === $calendarMonth->month && $calendarDate->year === $calendarMonth->year,
                    'visits' => $visits->take(3),
                    'count' => $visits->count(),
                    'overflow' => max(0, $visits->count() - 3),
                ];
            });

        $agenda = $calendarVisitsByDate
            ->filter(fn (Collection $visits, string $key): bool => str_starts_with($key, $calendarMonth->format('Y-m').'-'))
            ->sortKeys();

        return compact('dayVisits', 'strip', 'calendarDays', 'agenda', 'calendarStart', 'calendarEnd');
    }

    /**
     * @param  array{assignee?: string|int|null, status?: string|null, priority?: string|null}  $filters
     */
    public function backlog(Organization $organization, array $filters): Collection
    {
        return $this->scheduledQuery($organization, $filters)
            ->whereNull('scheduled_start_at')
            ->oldest()
            ->get();
    }

    /**
     * @param  array{assignee?: string|int|null, status?: string|null, priority?: string|null}  $filters
     */
    private function scheduledQuery(Organization $organization, array $filters): Builder
    {
        return $this->filteredQuery($organization, $filters)
            ->with(['serviceTicket.customer', 'serviceLocation', 'assignments.membership.user', 'confirmations.confirmedBy']);
    }

    /**
     * @param  array{assignee?: string|int|null, status?: string|null, priority?: string|null}  $filters
     */
    private function filteredQuery(Organization $organization, array $filters): Builder
    {
        return Visit::query()
            ->forOrganization($organization->id)
            ->where('status', '!=', 'canceled')
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(filled($filters['priority'] ?? null), fn (Builder $query) => $query->whereHas('serviceTicket', fn (Builder $ticket) => $ticket->where('priority', $filters['priority'])))
            ->when(filled($filters['assignee'] ?? null), fn (Builder $query) => $query->whereHas('assignments', fn (Builder $assignment) => $assignment->where('organization_membership_id', (int) $filters['assignee'])));
    }
}
