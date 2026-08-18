<?php

namespace Tests\Feature;

use App\Models\Capability;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Support\DispatchSchedule;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DispatchCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dispatch_renders_a_five_day_strip_with_one_day_navigation_and_an_independent_month(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 12:00:00 UTC');
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');

        $response = $this->actingAs($dispatcher)->get('/office/dispatch?date=2026-08-18&calendar_month=2026-09&priority=urgent');

        $response->assertOk()
            ->assertSee('Five-day workload')
            ->assertSee('aria-label="Previous day"', false)
            ->assertSee('aria-label="Next day"', false)
            ->assertSee('data-dispatch-calendar-grid', false)
            ->assertSee('data-dispatch-calendar-agenda', false)
            ->assertSee('September 2026');
        $response->assertViewHas('strip', function ($strip): bool {
            return $strip->count() === 5
                && $strip->first()['date']->format('Y-m-d') === '2026-08-18'
                && $strip->last()['date']->format('Y-m-d') === '2026-08-22';
        });
        $response->assertViewHas('calendarMonth', fn (CarbonImmutable $month): bool => $month->format('Y-m') === '2026-09');

        $html = html_entity_decode($response->getContent());
        $this->assertStringContainsString('priority=urgent&date=2026-08-17&calendar_month=2026-09', $html);
        $this->assertStringContainsString('priority=urgent&date=2026-08-19&calendar_month=2026-09', $html);
        $this->assertStringContainsString('priority=urgent&date=2026-08-18&calendar_month=2026-08', $html);
        $this->assertSame('America/Chicago', $organization->timezone);
    }

    public function test_invalid_date_and_month_fall_back_to_the_organization_today_month(): void
    {
        CarbonImmutable::setTestNow('2026-12-31 23:30:00 UTC');
        [$dispatcher] = $this->userWithRole('dispatcher');

        $response = $this->actingAs($dispatcher)->get('/office/dispatch?date=not-a-date&calendar_month=2026-99');

        $response->assertOk();
        $response->assertViewHas('date', fn (CarbonImmutable $date): bool => $date->format('Y-m-d') === '2026-12-31');
        $response->assertViewHas('calendarMonth', fn (CarbonImmutable $month): bool => $month->format('Y-m') === '2026-12');
    }

    public function test_filters_apply_to_selected_day_strip_and_calendar(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [, , $firstTech] = $this->userWithRole('technician', $organization);
        [, , $secondTech] = $this->userWithRole('technician', $organization);
        [$customer, $location] = $this->customerGraph($organization);

        $matchingTicket = $this->ticket($organization, $customer, $location, 'Urgent assigned work', 'urgent');
        $matching = $this->visit($matchingTicket, 'assigned', '2026-08-20 15:00:00');
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $matching->id, 'organization_membership_id' => $firstTech->id, 'is_lead' => true]);

        $wrongPriorityTicket = $this->ticket($organization, $customer, $location, 'Normal assigned work', 'normal');
        $wrongPriority = $this->visit($wrongPriorityTicket, 'assigned', '2026-08-20 16:00:00');
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $wrongPriority->id, 'organization_membership_id' => $firstTech->id, 'is_lead' => true]);

        $wrongAssigneeTicket = $this->ticket($organization, $customer, $location, 'Other technician work', 'urgent');
        $wrongAssignee = $this->visit($wrongAssigneeTicket, 'assigned', '2026-08-20 17:00:00');
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $wrongAssignee->id, 'organization_membership_id' => $secondTech->id, 'is_lead' => true]);

        $response = $this->actingAs($dispatcher)->get('/office/dispatch?date=2026-08-20&calendar_month=2026-08&status=assigned&priority=urgent&assignee='.$firstTech->id);

        $response->assertOk()->assertSee('Urgent assigned work')->assertDontSee('Normal assigned work')->assertDontSee('Other technician work');
        $response->assertViewHas('visits', fn ($visits): bool => $visits->pluck('id')->all() === [$matching->id]);
        $response->assertViewHas('strip', fn ($strip): bool => $strip->firstWhere(fn (array $day): bool => $day['date']->format('Y-m-d') === '2026-08-20')['count'] === 1);
        $response->assertViewHas('agenda', fn ($agenda): bool => $agenda->flatten()->pluck('id')->all() === [$matching->id]);
    }

    public function test_calendar_uses_organization_boundaries_limits_cells_and_excludes_canceled_archived_and_foreign_visits(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$customer, $location] = $this->customerGraph($organization);
        $ticket = $this->ticket($organization, $customer, $location, 'Visible calendar work');

        foreach (range(1, 4) as $index) {
            $this->visit($ticket, 'scheduled', '2026-08-01 '.str_pad((string) (12 + $index), 2, '0', STR_PAD_LEFT).':00:00');
        }
        $this->visit($ticket, 'canceled', '2026-08-01 18:00:00');
        $archived = $this->visit($ticket, 'scheduled', '2026-08-01 19:00:00');
        $archived->delete();

        $foreign = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$foreignCustomer, $foreignLocation] = $this->customerGraph($foreign);
        $this->visit($this->ticket($foreign, $foreignCustomer, $foreignLocation, 'Foreign calendar work'), 'scheduled', '2026-08-01 17:00:00');

        $response = $this->actingAs($dispatcher)->get('/office/dispatch?date=2026-08-01&calendar_month=2026-08');

        $response->assertOk()->assertSee('+1 more')->assertDontSee('Foreign calendar work');
        $response->assertViewHas('calendarDays', function ($days): bool {
            $day = $days->firstWhere(fn (array $item): bool => $item['date']->format('Y-m-d') === '2026-08-01');

            return $day['count'] === 4 && $day['visits']->count() === 3 && $day['overflow'] === 1;
        });
    }

    public function test_visit_local_timezone_is_shown_when_it_differs_from_the_organization(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$customer, $location] = $this->customerGraph($organization);
        $location->update(['timezone' => 'America/New_York']);
        $ticket = $this->ticket($organization, $customer, $location, 'Eastern visit');
        Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id,
            'status' => 'scheduled',
            'timezone' => 'America/New_York',
            'scheduled_start_at' => '2026-08-20 15:00:00',
            'scheduled_end_at' => '2026-08-20 16:00:00',
        ]);

        $this->actingAs($dispatcher)->get('/office/dispatch?date=2026-08-20&calendar_month=2026-08')
            ->assertOk()
            ->assertSee('11:00 AM')
            ->assertSee('EDT');
    }

    public function test_read_only_roles_tenant_scope_and_membership_overrides_remain_enforced(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$reviewer, , $reviewerMembership] = $this->userWithRole('reviewer', $organization);
        [, $foreignOrganization] = $this->userWithRole('dispatcher');
        [$foreignCustomer, $foreignLocation] = $this->customerGraph($foreignOrganization);
        $this->visit($this->ticket($foreignOrganization, $foreignCustomer, $foreignLocation, 'Hidden tenant visit'), 'scheduled', '2026-08-20 15:00:00');

        $this->actingAs($reviewer)->get('/office/dispatch?date=2026-08-20')->assertOk()->assertDontSee('Hidden tenant visit')->assertDontSee('New service ticket');

        $view = Capability::query()->where('key', 'service_tickets.view')->firstOrFail();
        $reviewerMembership->capabilityOverrides()->attach($view, ['effect' => 'deny']);
        $this->actingAs($reviewer)->get('/office/dispatch')->assertForbidden();

        $dispatcher->memberships()->where('organization_id', $organization->id)->update(['status' => 'inactive']);
        $this->actingAs($dispatcher)->get('/office/dispatch')->assertForbidden();
    }

    public function test_schedule_snapshot_uses_a_bounded_number_of_queries(): void
    {
        [, $organization] = $this->userWithRole('dispatcher');
        [$customer, $location] = $this->customerGraph($organization);
        $ticket = $this->ticket($organization, $customer, $location, 'Bounded calendar query');
        foreach (range(1, 12) as $day) {
            $this->visit($ticket, 'scheduled', '2026-08-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 15:00:00');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(DispatchSchedule::class)->forDispatch(
            $organization,
            CarbonImmutable::parse('2026-08-01', $organization->timezone),
            CarbonImmutable::parse('2026-08-01', $organization->timezone),
            [],
        );
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(11, $queryCount);
    }

    /** @return array{User, Organization, OrganizationMembership} */
    private function userWithRole(string $roleKey, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }

    /** @return array{Customer, ServiceLocation} */
    private function customerGraph(Organization $organization): array
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Calendar Site',
            'address_line_1' => '100 Main Street',
            'city' => 'Fort Worth',
            'state' => 'TX',
            'postal_code' => '76102',
            'timezone' => 'America/Chicago',
            'is_primary' => true,
            'active' => true,
        ]);

        return [$customer, $location];
    }

    private function ticket(Organization $organization, Customer $customer, ServiceLocation $location, string $title, string $priority = 'normal'): ServiceTicket
    {
        return ServiceTicket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => 'NDT-ST-2026-'.str_pad((string) (ServiceTicket::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => $title,
            'description' => 'Calendar test work',
            'priority' => $priority,
            'source' => 'phone',
            'status' => 'open',
        ]);
    }

    private function visit(ServiceTicket $ticket, string $status, string $start): Visit
    {
        return Visit::query()->create([
            'organization_id' => $ticket->organization_id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $ticket->service_location_id,
            'status' => $status,
            'timezone' => 'America/Chicago',
            'scheduled_start_at' => $start,
            'scheduled_end_at' => CarbonImmutable::parse($start)->addHour(),
        ]);
    }
}
