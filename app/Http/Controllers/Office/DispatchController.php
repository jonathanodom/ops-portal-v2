<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ServiceTicket;
use App\Support\DispatchSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DispatchController extends Controller
{
    public function index(Request $request, DispatchSchedule $schedule): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [ServiceTicket::class, $organization]);
        $date = $this->date($request, $organization);
        $calendarMonth = $this->calendarMonth($request, $organization, $date);
        $filters = [
            'assignee' => $request->query('assignee'),
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
        ];
        $filterQuery = array_filter($filters, fn ($value): bool => filled($value));
        $snapshot = $schedule->forDispatch($organization, $date, $calendarMonth, $filters);
        $backlog = $schedule->backlog($organization, $filters);
        $ticketBacklog = ServiceTicket::query()->forOrganization($organization->id)
            ->with(['customer', 'serviceLocation'])
            ->whereIn('status', ['open', 'on_hold'])
            ->whereDoesntHave('visits')
            ->oldest()
            ->get();

        return view('office.dispatch.index', [
            'date' => $date,
            'calendarMonth' => $calendarMonth,
            'filterQuery' => $filterQuery,
            'visits' => $snapshot['dayVisits'],
            'backlog' => $backlog,
            'ticketBacklog' => $ticketBacklog,
            'strip' => $snapshot['strip'],
            'calendarDays' => $snapshot['calendarDays'],
            'agenda' => $snapshot['agenda'],
            'memberships' => $organization->memberships()->with('user')->where('status', 'active')->orderBy('id')->get(),
            'priorities' => config('service_tickets.priorities'),
            'visitStatuses' => config('service_tickets.visit_statuses'),
        ]);
    }

    private function calendarMonth(Request $request, Organization $organization, CarbonImmutable $date): CarbonImmutable
    {
        $value = $request->query('calendar_month');
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return $date->startOfMonth();
        }

        try {
            $month = CarbonImmutable::createFromFormat('!Y-m', $value, $organization->timezone);

            return $month !== false && $month->format('Y-m') === $value ? $month->startOfMonth() : $date->startOfMonth();
        } catch (\Throwable) {
            return $date->startOfMonth();
        }
    }

    private function date(Request $request, Organization $organization): CarbonImmutable
    {
        $value = $request->query('date');
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return CarbonImmutable::now($organization->timezone)->startOfDay();
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $organization->timezone);

            return $date !== false && $date->format('Y-m-d') === $value
                ? $date->startOfDay()
                : CarbonImmutable::now($organization->timezone)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now($organization->timezone)->startOfDay();
        }
    }
}
