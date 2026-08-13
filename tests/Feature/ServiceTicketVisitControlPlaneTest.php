<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Support\ServiceTicketNumber;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceTicketVisitControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_ticket_creation_generates_scoped_number_defaults_contact_and_can_create_planned_visit(): void
    {
        CarbonImmutable::setTestNow('2026-07-30 12:00:00');
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$customer, $contact, $location] = $this->customerGraph($organization);

        $this->actingAs($dispatcher)->post('/office/service-tickets', $this->ticketPayload($customer, $location, [
            'create_visit' => '1',
        ]))->assertRedirect();

        $ticket = ServiceTicket::query()->firstOrFail();
        $this->assertSame('NDT-ST-2026-0001', $ticket->ticket_number);
        $this->assertSame($contact->id, $ticket->contact_id);
        $this->assertDatabaseHas('visits', ['service_ticket_id' => $ticket->id, 'status' => 'planned']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'service_ticket.created', 'organization_id' => $organization->id]);

        $other = Organization::factory()->create(['timezone' => 'America/Chicago']);
        DB::transaction(fn () => app(ServiceTicketNumber::class)->next($other));
        $this->assertSame(1, DocumentSequence::query()->where('organization_id', $other->id)->value('current_value'));
        DocumentSequence::query()->where('organization_id', $organization->id)->update(['current_value' => 9999]);
        $number = DB::transaction(fn () => app(ServiceTicketNumber::class)->next($organization));
        $this->assertSame('NDT-ST-2026-10000', $number);
    }

    public function test_failed_initial_visit_rolls_back_ticket_and_sequence(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$customer, , $location] = $this->customerGraph($organization);

        $this->actingAs($dispatcher)->post('/office/service-tickets', $this->ticketPayload($customer, $location, [
            'create_visit' => '1',
            'scheduled_start' => '2026-03-08T02:30',
            'scheduled_end' => '2026-03-08T03:30',
        ]))->assertSessionHasErrors('scheduled_start');

        $this->assertDatabaseCount('service_tickets', 0);
        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseCount('document_sequences', 0);
    }

    public function test_scheduling_assigns_one_lead_and_assigned_technician_can_execute(): void
    {
        CarbonImmutable::setTestNow('2026-07-30 12:00:00');
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$technician, , $techMembership] = $this->userWithRole('technician', $organization);
        [$customer, , $location] = $this->customerGraph($organization);
        $ticket = $this->ticket($organization, $customer, $location);
        $visit = $this->visit($ticket);

        $this->actingAs($dispatcher)->put("/office/visits/{$visit->id}", [
            'scheduled_start' => '2026-07-30T14:00',
            'scheduled_end' => '2026-07-30T16:00',
            'assignees' => [$techMembership->id],
            'lead_membership_id' => $techMembership->id,
        ])->assertRedirect();

        $this->assertSame('assigned', $visit->fresh()->status);
        $this->assertDatabaseHas('visit_assignments', ['visit_id' => $visit->id, 'organization_membership_id' => $techMembership->id, 'is_lead' => true]);
        $this->actingAs($technician)->get('/field')->assertOk()->assertSee($ticket->title);
        $this->actingAs($technician)->post("/field/visits/{$visit->id}/transition", ['status' => 'en_route'])->assertRedirect();
        $this->assertNotNull($visit->fresh()->en_route_at);
        $this->actingAs($technician)->post("/field/visits/{$visit->id}/transition", ['status' => 'on_site'])->assertRedirect();
        $this->assertSame('on_site', $visit->fresh()->status);
    }

    public function test_unassigned_technician_is_denied_and_inspect_all_does_not_grant_execution(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$technician] = $this->userWithRole('technician', $organization);
        [$customer, , $location] = $this->customerGraph($organization);
        $visit = $this->visit($this->ticket($organization, $customer, $location), 'assigned');

        $this->actingAs($technician)->get("/field/visits/{$visit->id}")->assertForbidden();
        $this->actingAs($dispatcher)->get("/field/visits/{$visit->id}")->assertOk();
        $this->actingAs($dispatcher)->post("/field/visits/{$visit->id}/transition", ['status' => 'en_route'])->assertForbidden();

        $executeAny = Capability::query()->where('key', 'visits.execute_any')->firstOrFail();
        $dispatcherMembership = $dispatcher->memberships()->where('organization_id', $organization->id)->firstOrFail();
        $dispatcherMembership->capabilityOverrides()->attach($executeAny, ['effect' => 'grant']);
        $this->actingAs($dispatcher)->post("/field/visits/{$visit->id}/transition", ['status' => 'en_route'])->assertRedirect();
    }

    public function test_hold_blocks_field_start_and_cancel_cascades_without_exposing_reasons_in_audit(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$technician, , $membership] = $this->userWithRole('technician', $organization);
        [$customer, , $location] = $this->customerGraph($organization);
        $ticket = $this->ticket($organization, $customer, $location);
        $visit = $this->visit($ticket, 'assigned');
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);

        $this->actingAs($dispatcher)->post("/office/service-tickets/{$ticket->id}/transition", ['status' => 'on_hold', 'reason' => 'Sensitive customer context'])->assertRedirect();
        $this->actingAs($technician)->post("/field/visits/{$visit->id}/transition", ['status' => 'en_route'])->assertSessionHasErrors('status');
        $this->actingAs($dispatcher)->post("/office/service-tickets/{$ticket->id}/transition", ['status' => 'canceled', 'reason' => 'Private cancellation details'])->assertRedirect();

        $this->assertSame('canceled', $ticket->fresh()->status);
        $this->assertSame('canceled', $visit->fresh()->status);
        $this->assertStringNotContainsString('Private cancellation details', AuditEvent::query()->get()->pluck('metadata')->toJson());
    }

    public function test_return_visit_stays_on_same_ticket_and_requires_on_site_source(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$customer, , $location] = $this->customerGraph($organization);
        $ticket = $this->ticket($organization, $customer, $location);
        $visit = $this->visit($ticket, 'on_site');

        $this->actingAs($dispatcher)->post("/office/visits/{$visit->id}/return", ['reason' => 'Additional equipment needed'])->assertRedirect();
        $return = Visit::query()->where('return_of_visit_id', $visit->id)->firstOrFail();
        $this->assertSame($ticket->id, $return->service_ticket_id);
        $this->assertSame('planned', $return->status);
    }

    public function test_overlap_requires_confirmation_and_records_safe_override(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [, , $membership] = $this->userWithRole('technician', $organization);
        [$customer, , $location] = $this->customerGraph($organization);
        $ticket = $this->ticket($organization, $customer, $location);
        $existing = $this->visit($ticket, 'assigned', '2026-08-01 15:00:00', '2026-08-01 17:00:00');
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $existing->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);
        $next = $this->visit($ticket);

        $payload = ['scheduled_start' => '2026-08-01T10:30', 'scheduled_end' => '2026-08-01T11:30', 'assignees' => [$membership->id], 'lead_membership_id' => $membership->id];
        $this->actingAs($dispatcher)->put("/office/visits/{$next->id}", $payload)->assertSessionHasErrors('schedule_conflict');
        $this->actingAs($dispatcher)->put("/office/visits/{$next->id}", $payload + ['confirm_conflicts' => '1'])->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['event_type' => 'visit.schedule_conflict_overridden', 'subject_id' => $next->id]);
    }

    public function test_office_role_matrix_cross_organization_scope_and_explicit_override(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$reviewer, , $reviewMembership] = $this->userWithRole('reviewer', $organization);
        [$billing] = $this->userWithRole('billing', $organization);
        [$technician] = $this->userWithRole('technician', $organization);
        [$customer, , $location] = $this->customerGraph($organization);
        $ticket = $this->ticket($organization, $customer, $location);
        $foreign = Organization::factory()->create();
        [$foreignCustomer, , $foreignLocation] = $this->customerGraph($foreign);
        $foreignTicket = $this->ticket($foreign, $foreignCustomer, $foreignLocation);

        $this->actingAs($reviewer)->get('/office/service-tickets')->assertOk();
        $this->actingAs($billing)->get("/office/service-tickets/{$ticket->id}")->assertOk();
        $this->actingAs($reviewer)->get('/office/service-tickets/create')->assertForbidden();
        $this->actingAs($technician)->get('/office/service-tickets')->assertForbidden();
        $this->actingAs($dispatcher)->get("/office/service-tickets/{$foreignTicket->id}?organization_id={$foreign->id}")->assertNotFound();

        $capability = Capability::query()->where('key', 'service_tickets.view')->firstOrFail();
        $reviewMembership->capabilityOverrides()->attach($capability, ['effect' => 'deny']);
        $this->actingAs($reviewer)->get('/office/service-tickets')->assertForbidden();
    }

    public function test_ticket_search_archive_guards_and_field_projection_hide_private_content(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$technician, , $membership] = $this->userWithRole('technician', $organization);
        [$customer, $contact, $location] = $this->customerGraph($organization);
        $customer->update(['notes' => 'PRIVATE CUSTOMER NOTE']);
        $location->update(['site_notes' => 'PRIVATE SITE NOTE', 'access_instructions' => 'Use loading dock']);
        $ticket = $this->ticket($organization, $customer, $location);
        $ticket->notes()->create(['organization_id' => $organization->id, 'author_id' => $dispatcher->id, 'body' => 'PRIVATE TICKET NOTE', 'created_at' => now()]);
        $visit = $this->visit($ticket, 'assigned', now()->addHour(), now()->addHours(2));
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);

        $this->actingAs($dispatcher)->get('/office/customers?search='.urlencode($ticket->ticket_number))->assertOk()->assertSee($customer->display_name);
        $this->actingAs($dispatcher)->put("/office/customers/{$customer->id}", ['type' => 'business', 'display_name' => $customer->display_name, 'status' => 'inactive'])->assertSessionHasErrors('status');
        $response = $this->actingAs($technician)->get("/field/visits/{$visit->id}");
        $response->assertOk()->assertSee('Use loading dock')->assertSee($contact->name)
            ->assertDontSee('PRIVATE CUSTOMER NOTE')->assertDontSee('PRIVATE SITE NOTE')->assertDontSee('PRIVATE TICKET NOTE');
    }

    public function test_today_uses_organization_day_and_upcoming_stops_after_seven_days(): void
    {
        CarbonImmutable::setTestNow('2026-07-30 12:00:00 UTC');
        [, $organization] = $this->userWithRole('dispatcher');
        [$technician, , $membership] = $this->userWithRole('technician', $organization);
        [$customer, , $location] = $this->customerGraph($organization);
        $ticket = $this->ticket($organization, $customer, $location);
        $today = $this->visit($ticket, 'assigned', '2026-07-30 18:00:00', '2026-07-30 19:00:00');
        $upcoming = $this->visit($ticket, 'assigned', '2026-08-03 18:00:00', '2026-08-03 19:00:00');
        $outside = $this->visit($ticket, 'assigned', '2026-08-08 18:00:00', '2026-08-08 19:00:00');
        foreach ([$today, $upcoming, $outside] as $visit) {
            VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);
        }

        $this->actingAs($technician)->get('/field')
            ->assertOk()
            ->assertSee('Today')
            ->assertSee('Mon, Aug 3')
            ->assertDontSee('Sat, Aug 8');
    }

    public function test_service_ticket_workspace_uses_responsive_operational_conventions(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$customer, , $location] = $this->customerGraph($organization);
        $ticket = $this->ticket($organization, $customer, $location);

        $this->actingAs($dispatcher)->get('/office/service-tickets')
            ->assertOk()
            ->assertSee('data-office-width="workspace"', false)
            ->assertSee('aria-label="Service Ticket filters"', false)
            ->assertSee('data-office-table', false)
            ->assertSee('data-office-mobile-list', false)
            ->assertSee($ticket->ticket_number)
            ->assertSee('Customer and location')
            ->assertSee('All assignees');
    }

    public function test_visits_use_ticket_relative_numbers_and_never_reuse_them(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$customer, , $location] = $this->customerGraph($organization);
        $firstTicket = $this->ticket($organization, $customer, $location);
        $secondTicket = $this->ticket($organization, $customer, $location);

        $first = $this->visit($firstTicket);
        $second = $this->visit($firstTicket);
        $return = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $firstTicket->id,
            'service_location_id' => $location->id,
            'return_of_visit_id' => $first->id,
            'status' => 'planned',
            'timezone' => 'America/Chicago',
        ]);

        $this->assertSame(1, $first->ticket_visit_number);
        $this->assertSame(2, $second->ticket_visit_number);
        $this->assertSame(3, $return->ticket_visit_number);
        $this->assertSame('Visit 3 · Return of Visit 1', $return->displayLabel());

        $second->delete();
        $return->forceDelete();
        $next = $this->visit($firstTicket);
        $otherTicketFirst = $this->visit($secondTicket);

        $this->assertSame(4, $next->ticket_visit_number);
        $this->assertSame(1, $otherTicketFirst->ticket_visit_number);
        $this->assertSame(5, $firstTicket->fresh()->next_visit_number);
        $this->assertDatabaseHas('visits', [
            'service_ticket_id' => $firstTicket->id,
            'ticket_visit_number' => 2,
            'deleted_at' => $second->deleted_at,
        ]);
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

    /** @return array{Customer, Contact, ServiceLocation} */
    private function customerGraph(Organization $organization): array
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => 'Phase Two Customer']);
        $contact = Contact::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'name' => 'Alex Contact', 'phone' => '817-555-1212', 'email' => 'alex@example.test', 'is_preferred' => true, 'active' => true]);
        $location = ServiceLocation::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'primary_contact_id' => $contact->id, 'name' => 'Main Site', 'address_line_1' => '100 Main', 'city' => 'Fort Worth', 'state' => 'TX', 'postal_code' => '76102', 'timezone' => 'America/Chicago', 'is_primary' => true, 'active' => true]);

        return [$customer, $contact, $location];
    }

    private function ticket(Organization $organization, Customer $customer, ServiceLocation $location): ServiceTicket
    {
        return ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-'.str_pad((string) (ServiceTicket::query()->count() + 1), 4, '0', STR_PAD_LEFT), 'title' => 'Network service call', 'description' => 'Diagnose the network outage', 'customer_visible_summary' => 'Restore connectivity', 'priority' => 'normal', 'source' => 'phone', 'status' => 'open']);
    }

    private function visit(ServiceTicket $ticket, string $status = 'planned', mixed $start = null, mixed $end = null): Visit
    {
        return Visit::query()->create(['organization_id' => $ticket->organization_id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $ticket->service_location_id, 'status' => $status, 'timezone' => 'America/Chicago', 'scheduled_start_at' => $start, 'scheduled_end_at' => $end]);
    }

    private function ticketPayload(Customer $customer, ServiceLocation $location, array $extra = []): array
    {
        return ['customer_id' => $customer->id, 'service_location_id' => $location->id, 'title' => 'Network service call', 'description' => 'Diagnose issue', 'priority' => 'normal', 'source' => 'phone'] + $extra;
    }
}
