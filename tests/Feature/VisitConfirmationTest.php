<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitConfirmation;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitConfirmationTest extends TestCase
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

    public function test_day_before_state_is_derived_and_dispatcher_can_record_append_only_evidence(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 15:00:00 UTC');
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$ticket, $visit] = $this->ticketVisit($organization, '2026-09-02 15:00:00');

        $this->assertSame('needs_confirmation', $visit->confirmationState(now(), $organization->timezone));
        $this->actingAs($dispatcher)->get(route('office.dispatch.index', ['date' => '2026-09-02']))
            ->assertOk()->assertSee('Needs Confirmation');

        $this->actingAs($dispatcher)->post(route('office.visits.confirmation.store', $visit), [
            'confirmation_method' => 'text',
            'confirmation_note' => 'Customer replied with private scheduling detail.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $confirmation = VisitConfirmation::query()->sole();
        $this->assertSame($dispatcher->id, $confirmation->confirmed_by_id);
        $this->assertSame('text', $confirmation->method);
        $this->assertSame($visit->fresh()->schedule_version, $confirmation->schedule_version);
        $this->assertSame('confirmed', $visit->fresh()->confirmationState(now(), $organization->timezone));

        $response = $this->actingAs($dispatcher)->get(route('office.service-tickets.show', $ticket));
        $response->assertOk()->assertSee('Confirmed')->assertSee('via Text')->assertDontSee('name="confirmation_method"', false);

        $audit = AuditEvent::query()->where('event_type', 'visit.confirmed')->sole();
        $this->assertSame($dispatcher->id, $audit->actor_id);
        $this->assertSame('text', $audit->metadata['method']);
        $this->assertStringNotContainsString('private scheduling detail', json_encode($audit->metadata));
    }

    public function test_rescheduling_invalidates_prior_confirmation_without_mutating_history(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 15:00:00 UTC');
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$ticket, $visit] = $this->ticketVisit($organization, '2026-09-02 15:00:00');

        $this->actingAs($dispatcher)->post(route('office.visits.confirmation.store', $visit), [
            'confirmation_method' => 'call',
        ])->assertSessionHasNoErrors();
        $originalVersion = $visit->fresh()->schedule_version;

        $this->actingAs($dispatcher)->put(route('office.visits.update', $visit), [
            'scheduled_start' => '2026-09-04T10:00',
            'scheduled_end' => '2026-09-04T11:00',
        ])->assertRedirect(route('office.service-tickets.show', $ticket))->assertSessionHasNoErrors();

        $visit->refresh();
        $this->assertSame($originalVersion + 1, $visit->schedule_version);
        $this->assertSame('scheduled', $visit->confirmationState(now(), $organization->timezone));
        $this->assertNull($visit->confirmationForCurrentSchedule());
        $this->assertSame(1, VisitConfirmation::query()->count());
    }

    public function test_confirmation_enforces_validation_authorization_and_organization_scope(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 15:00:00 UTC');
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        [$ticket, $visit] = $this->ticketVisit($organization, '2026-09-02 15:00:00');

        $this->actingAs($dispatcher)->post(route('office.visits.confirmation.store', $visit), [
            'confirmation_method' => 'carrier_pigeon',
        ])->assertSessionHasErrors('confirmation_method');
        $this->assertDatabaseCount('visit_confirmations', 0);

        $this->actingAs($reviewer)->post(route('office.visits.confirmation.store', $visit), [
            'confirmation_method' => 'email',
        ])->assertForbidden();
        $this->actingAs($reviewer)->get(route('office.service-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Needs Confirmation')
            ->assertDontSee('name="confirmation_method"', false);

        [$foreignDispatcher, $foreignOrganization] = $this->userWithRole('dispatcher');
        $this->actingAs($foreignDispatcher)->post(route('office.visits.confirmation.store', $visit), [
            'confirmation_method' => 'call',
        ])->assertNotFound();
        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $foreignOrganization->id,
            'event_type' => 'security.cross_organization_record_denied',
        ]);
    }

    /** @return array{User, Organization, OrganizationMembership} */
    private function userWithRole(string $roleKey, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }

    /** @return array{ServiceTicket, Visit} */
    private function ticketVisit(Organization $organization, string $start): array
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Confirmation Site',
            'address_line_1' => '100 Main Street',
            'city' => 'Fort Worth',
            'state' => 'TX',
            'postal_code' => '76102',
            'timezone' => 'America/Chicago',
            'is_primary' => true,
            'active' => true,
        ]);
        $ticket = ServiceTicket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => 'NDT-ST-2026-'.str_pad((string) (ServiceTicket::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'Confirm appointment',
            'description' => 'Visit confirmation test',
            'priority' => 'normal',
            'source' => 'phone',
            'status' => 'open',
        ]);
        $visit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id,
            'status' => 'scheduled',
            'timezone' => 'America/Chicago',
            'scheduled_start_at' => $start,
            'scheduled_end_at' => CarbonImmutable::parse($start)->addHour(),
        ]);

        return [$ticket, $visit];
    }
}
