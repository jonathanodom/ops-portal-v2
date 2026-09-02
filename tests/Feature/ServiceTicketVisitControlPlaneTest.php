<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\Closeout;
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

    public function test_site_survey_purpose_and_non_billable_disposition_are_saved_and_visible(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$customer, , $location] = $this->customerGraph($organization);

        $this->actingAs($dispatcher)->post('/office/service-tickets', $this->ticketPayload($customer, $location, [
            'purpose' => 'site_survey',
            'billing_disposition' => 'non_billable',
        ]))->assertRedirect();

        $ticket = ServiceTicket::query()->firstOrFail();
        $this->assertSame('site_survey', $ticket->purpose);
        $this->assertSame('non_billable', $ticket->billing_disposition);
        $this->actingAs($dispatcher)->get("/office/service-tickets/{$ticket->id}")
            ->assertOk()
            ->assertSee('Site / Survey Visit')
            ->assertSee('Non-billable');
    }

    public function test_original_and_generated_follow_up_ticket_render_linked_return_context(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$customer, , $location] = $this->customerGraph($organization);
        $source = $this->ticket($organization, $customer, $location);
        $source->update(['purpose' => 'installation_project', 'title' => 'Parking Lot Camera Installation']);
        $sourceVisit = $this->visit($source, 'pending_closeout');
        $sourceCloseout = $this->returnCloseout($sourceVisit, [
            'return_reason' => 'Final exterior camera requires lift access.',
            'unfinished_work' => 'Install and aim final parking-lot camera.',
            'needed_equipment' => '35-foot lift',
        ]);
        $followUp = $this->followUpTicket($source, $sourceCloseout);

        $this->actingAs($dispatcher)->get(route('office.service-tickets.show', $source))
            ->assertOk()
            ->assertSee('data-return-visit-section', false)
            ->assertSee('Final exterior camera requires lift access.')
            ->assertSee('Install and aim final parking-lot camera.')
            ->assertSee('35-foot lift')
            ->assertSee($followUp->ticket_number)
            ->assertSee(route('office.service-tickets.show', $followUp), false);

        $this->actingAs($dispatcher)->get(route('office.service-tickets.show', $followUp))
            ->assertOk()
            ->assertSee('data-return-follow-up-source', false)
            ->assertSee('Return Visit Follow-Up')
            ->assertSee('Installation / Project')
            ->assertSee($source->ticket_number)
            ->assertSee(route('office.service-tickets.show', $source), false)
            ->assertSee('Final exterior camera requires lift access.');
    }

    public function test_optional_and_legacy_return_context_render_without_empty_or_broken_links(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$customer, , $location] = $this->customerGraph($organization);
        $legacy = $this->ticket($organization, $customer, $location);
        $legacyCloseout = $this->returnCloseout($this->visit($legacy, 'pending_closeout'), [
            'return_reason' => 'Customer access required.',
        ]);

        $this->actingAs($dispatcher)->get(route('office.service-tickets.show', $legacy))
            ->assertOk()
            ->assertSee('Customer access required.')
            ->assertSee('Not linked — historical closeout')
            ->assertDontSee('Unfinished Work')
            ->assertDontSee('Needed Parts / Equipment');

        $followUp = $this->followUpTicket($legacy, $legacyCloseout);
        $this->actingAs($dispatcher)->get(route('office.service-tickets.show', $followUp))
            ->assertOk()
            ->assertSee('Customer access required.')
            ->assertDontSee('Unfinished Work')
            ->assertDontSee('Needed Parts / Equipment');

        $normal = $this->ticket($organization, $customer, $location);
        $this->actingAs($dispatcher)->get(route('office.service-tickets.show', $normal))
            ->assertOk()
            ->assertDontSee('data-return-visit-section', false)
            ->assertDontSee('data-return-follow-up-source', false);
    }

    public function test_middle_follow_up_ticket_renders_parent_and_child_links_with_normal_authorization(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$customer, , $location] = $this->customerGraph($organization);
        $source = $this->ticket($organization, $customer, $location);
        $firstCloseout = $this->returnCloseout($this->visit($source, 'pending_closeout'));
        $middle = $this->followUpTicket($source, $firstCloseout);
        $secondCloseout = $this->returnCloseout($this->visit($middle, 'pending_closeout'), ['return_reason' => 'A second return is required.']);
        $last = $this->followUpTicket($middle, $secondCloseout);

        $this->actingAs($dispatcher)->get(route('office.service-tickets.show', $middle))
            ->assertOk()
            ->assertSee(route('office.service-tickets.show', $source), false)
            ->assertSee(route('office.service-tickets.show', $last), false);

        [$otherDispatcher] = $this->userWithRole('dispatcher');
        $this->actingAs($otherDispatcher)->get(route('office.service-tickets.show', $middle))->assertNotFound();
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

    public function test_scheduling_automatically_assigns_single_crew_member_as_lead_and_they_can_execute(): void
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
        ])->assertRedirect();

        $this->assertSame('assigned', $visit->fresh()->status);
        $this->assertDatabaseHas('visit_assignments', ['visit_id' => $visit->id, 'organization_membership_id' => $techMembership->id, 'is_lead' => true]);
        $audit = AuditEvent::query()->where('event_type', 'visit.scheduled')->where('subject_id', $visit->id)->latest('id')->firstOrFail();
        $this->assertSame($techMembership->id, $audit->metadata['lead_membership_id']);
        $this->assertSame('automatic', $audit->metadata['lead_assignment_mode']);
        $this->actingAs($technician)->get('/field')->assertOk()->assertSee($ticket->title);
        $this->actingAs($technician)->post("/field/visits/{$visit->id}/transition", ['status' => 'en_route'])->assertRedirect();
        $this->assertNotNull($visit->fresh()->en_route_at);
        $this->actingAs($technician)->post("/field/visits/{$visit->id}/transition", ['status' => 'on_site'])->assertRedirect();
        $this->assertSame('on_site', $visit->fresh()->status);
    }

    public function test_initial_visit_automatically_assigns_its_only_crew_member_as_lead(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [, , $membership] = $this->userWithRole('technician', $organization);
        [$customer, , $location] = $this->customerGraph($organization);

        $this->actingAs($dispatcher)->post('/office/service-tickets', $this->ticketPayload($customer, $location, [
            'create_visit' => '1',
            'scheduled_start' => '2026-08-20T09:00',
            'scheduled_end' => '2026-08-20T10:00',
            'assignees' => [$membership->id],
        ]))->assertRedirect();

        $visit = Visit::query()->firstOrFail();
        $this->assertSame('assigned', $visit->status);
        $this->assertDatabaseHas('visit_assignments', [
            'visit_id' => $visit->id,
            'organization_membership_id' => $membership->id,
            'is_lead' => true,
        ]);
    }

    public function test_zero_assignees_results_in_no_lead_even_when_a_lead_value_is_submitted(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [, , $membership] = $this->userWithRole('technician', $organization);
        [$customer, , $location] = $this->customerGraph($organization);
        $visit = $this->visit($this->ticket($organization, $customer, $location));

        $this->actingAs($dispatcher)->put("/office/visits/{$visit->id}", [
            'scheduled_start' => '2026-08-20T09:00',
            'scheduled_end' => '2026-08-20T10:00',
            'lead_membership_id' => $membership->id,
        ])->assertRedirect();

        $this->assertSame('scheduled', $visit->fresh()->status);
        $this->assertDatabaseMissing('visit_assignments', ['visit_id' => $visit->id]);
        $audit = AuditEvent::query()->where('event_type', 'visit.scheduled')->where('subject_id', $visit->id)->latest('id')->firstOrFail();
        $this->assertNull($audit->metadata['lead_membership_id']);
        $this->assertSame('none', $audit->metadata['lead_assignment_mode']);
    }

    public function test_multiple_assignees_still_require_one_explicit_assigned_lead(): void
    {
        [$dispatcher, $organization, $dispatcherMembership] = $this->userWithRole('dispatcher');
        [, , $firstMembership] = $this->userWithRole('technician', $organization);
        [, , $secondMembership] = $this->userWithRole('technician', $organization);
        [$customer, , $location] = $this->customerGraph($organization);
        $visit = $this->visit($this->ticket($organization, $customer, $location));
        $payload = [
            'scheduled_start' => '2026-08-20T09:00',
            'scheduled_end' => '2026-08-20T10:00',
            'assignees' => [$firstMembership->id, $secondMembership->id],
        ];

        $this->actingAs($dispatcher)->put("/office/visits/{$visit->id}", $payload)
            ->assertSessionHasErrors('lead_membership_id');
        $this->assertSame('planned', $visit->fresh()->status);
        $this->assertDatabaseMissing('visit_assignments', ['visit_id' => $visit->id]);

        $this->actingAs($dispatcher)->put("/office/visits/{$visit->id}", $payload + [
            'lead_membership_id' => $dispatcherMembership->id,
        ])->assertSessionHasErrors('lead_membership_id');
        $this->assertDatabaseMissing('visit_assignments', ['visit_id' => $visit->id]);

        $this->actingAs($dispatcher)->put("/office/visits/{$visit->id}", $payload + [
            'lead_membership_id' => $secondMembership->id,
        ])->assertRedirect();

        $this->assertSame(2, $visit->assignments()->count());
        $this->assertSame(1, $visit->assignments()->where('is_lead', true)->count());
        $this->assertDatabaseHas('visit_assignments', [
            'visit_id' => $visit->id,
            'organization_membership_id' => $secondMembership->id,
            'is_lead' => true,
        ]);
        $audit = AuditEvent::query()->where('event_type', 'visit.scheduled')->where('subject_id', $visit->id)->latest('id')->firstOrFail();
        $this->assertSame('explicit', $audit->metadata['lead_assignment_mode']);
    }

    public function test_single_assignee_must_still_be_an_active_field_member_of_the_visit_organization(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [, , $foreignMembership] = $this->userWithRole('technician');
        [$customer, , $location] = $this->customerGraph($organization);
        $visit = $this->visit($this->ticket($organization, $customer, $location));

        $this->actingAs($dispatcher)->put("/office/visits/{$visit->id}", [
            'scheduled_start' => '2026-08-20T09:00',
            'scheduled_end' => '2026-08-20T10:00',
            'assignees' => [$foreignMembership->id],
        ])->assertSessionHasErrors('assignees');

        $this->assertSame('planned', $visit->fresh()->status);
        $this->assertDatabaseMissing('visit_assignments', ['visit_id' => $visit->id]);
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

        $payload = ['scheduled_start' => '2026-08-01T10:30', 'scheduled_end' => '2026-08-01T11:30', 'assignees' => [$membership->id]];
        $this->actingAs($dispatcher)->put("/office/visits/{$next->id}", $payload)->assertSessionHasErrors('schedule_conflict');
        $this->actingAs($dispatcher)->put("/office/visits/{$next->id}", $payload + ['confirm_conflicts' => '1'])->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['event_type' => 'visit.schedule_conflict_overridden', 'subject_id' => $next->id]);
        $this->assertDatabaseHas('visit_assignments', [
            'visit_id' => $next->id,
            'organization_membership_id' => $membership->id,
            'is_lead' => true,
        ]);
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

    public function test_visit_assignment_forms_explain_the_automatic_single_crew_lead_rule(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        $this->userWithRole('technician', $organization);
        [$customer, , $location] = $this->customerGraph($organization);
        $visit = $this->visit($this->ticket($organization, $customer, $location));

        $this->actingAs($dispatcher)->get('/office/service-tickets/create')
            ->assertOk()
            ->assertSee('aria-describedby="initial-lead-membership-help"', false)
            ->assertSee('A single crew member becomes lead automatically.');

        $this->actingAs($dispatcher)->get("/office/visits/{$visit->id}/edit")
            ->assertOk()
            ->assertSee('aria-describedby="lead-membership-help"', false)
            ->assertSee('A single crew member becomes lead automatically.');
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

    private function returnCloseout(Visit $visit, array $attributes = []): Closeout
    {
        $closeout = Closeout::query()->create(array_merge([
            'organization_id' => $visit->organization_id,
            'visit_id' => $visit->id,
            'version' => 1,
            'status' => 'submitted',
            'content_version' => 1,
            'outcome' => 'needs_return_trip',
            'work_performed' => 'Completed the available work.',
            'return_reason' => 'Return work is required.',
            'submitted_at' => now(),
        ], $attributes));

        $visit->update(['current_closeout_id' => $closeout->id]);

        return $closeout;
    }

    private function followUpTicket(ServiceTicket $source, Closeout $closeout): ServiceTicket
    {
        return ServiceTicket::query()->create([
            'organization_id' => $source->organization_id,
            'customer_id' => $source->customer_id,
            'service_location_id' => $source->service_location_id,
            'contact_id' => $source->contact_id,
            'ticket_number' => 'NDT-ST-2026-'.str_pad((string) (ServiceTicket::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'Return Visit — '.$source->title,
            'description' => $closeout->return_reason,
            'priority' => $source->priority,
            'source' => 'internal',
            'purpose' => $source->canonicalPurpose(),
            'billing_disposition' => $source->billing_disposition ?? 'billable',
            'status' => 'open',
            'return_follow_up_source_ticket_id' => $source->id,
            'return_follow_up_source_closeout_id' => $closeout->id,
            'return_follow_up_original_purpose' => $source->purpose,
            'return_follow_up_status' => 'needs_review',
        ]);
    }

    private function ticketPayload(Customer $customer, ServiceLocation $location, array $extra = []): array
    {
        return ['customer_id' => $customer->id, 'service_location_id' => $location->id, 'title' => 'Network service call', 'description' => 'Diagnose issue', 'priority' => 'normal', 'source' => 'phone'] + $extra;
    }
}
