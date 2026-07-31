<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ServiceTicket;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DispatchController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [ServiceTicket::class, $organization]);
        $date = $this->date($request, $organization);
        $start = $date->startOfDay()->utc();
        $end = $date->addDay()->startOfDay()->utc();
        $base = Visit::query()->forOrganization($organization->id)
            ->with(['serviceTicket.customer', 'serviceLocation', 'assignments.membership.user'])
            ->where('status', '!=', 'canceled')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($query) => $query->whereHas('serviceTicket', fn ($ticket) => $ticket->where('priority', $request->string('priority'))))
            ->when($request->filled('assignee'), fn ($query) => $query->whereHas('assignments', fn ($assignment) => $assignment->where('organization_membership_id', $request->integer('assignee'))));

        $visits = (clone $base)->where('scheduled_start_at', '>=', $start)->where('scheduled_start_at', '<', $end)
            ->orderBy('scheduled_start_at')->get();
        $backlog = (clone $base)->whereNull('scheduled_start_at')->oldest()->get();
        $ticketBacklog = ServiceTicket::query()->forOrganization($organization->id)
            ->with(['customer', 'serviceLocation'])
            ->whereIn('status', ['open', 'on_hold'])
            ->whereDoesntHave('visits')
            ->oldest()
            ->get();
        $week = collect(range(0, 6))->map(function (int $offset) use ($date, $organization): array {
            $day = $date->addDays($offset);

            return [
                'date' => $day,
                'count' => Visit::query()->forOrganization($organization->id)
                    ->where('status', '!=', 'canceled')
                    ->whereBetween('scheduled_start_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()])
                    ->count(),
            ];
        });

        return view('office.dispatch.index', [
            'date' => $date,
            'visits' => $visits,
            'backlog' => $backlog,
            'ticketBacklog' => $ticketBacklog,
            'week' => $week,
            'memberships' => $organization->memberships()->with('user')->where('status', 'active')->orderBy('id')->get(),
            'priorities' => config('service_tickets.priorities'),
            'visitStatuses' => config('service_tickets.visit_statuses'),
        ]);
    }

    private function date(Request $request, Organization $organization): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($request->query('date', 'today'), $organization->timezone)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now($organization->timezone)->startOfDay();
        }
    }
}
