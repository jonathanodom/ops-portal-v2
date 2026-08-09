<?php

namespace Tests\Feature;

use App\Models\Closeout;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Models\VisitTimeEntry;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficeExecutionParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_super_admin_can_execute_an_unassigned_visit_from_the_office_modal(): void
    {
        [$organization, $ticket, $visit] = $this->ticketGraph('assigned');
        [$admin] = $this->userWithRole('super_admin', $organization);

        $this->actingAs($admin)->get("/office/service-tickets/{$ticket->id}")
            ->assertOk()->assertSee('Open execution')->assertSee('Execution workspace')
            ->assertSee('sm:h-[92dvh]', false)->assertSee('sm:w-[96vw]', false);

        $this->actingAs($admin)->post("/office/visits/{$visit->id}/execution/transition", ['status' => 'en_route'])
            ->assertRedirectContains("execution_visit={$visit->id}")->assertSessionHasNoErrors();

        $this->assertSame('en_route', $visit->fresh()->status);
        $this->assertDatabaseHas('visit_time_entries', [
            'visit_id' => $visit->id,
            'user_id' => $admin->id,
            'category' => 'travel',
            'active_user_id' => $admin->id,
        ]);
    }

    public function test_visit_cancellation_requires_confirmation_and_atomically_stops_active_time(): void
    {
        [$organization, , $visit] = $this->ticketGraph('assigned');
        [$admin] = $this->userWithRole('super_admin', $organization);
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/execution/transition", ['status' => 'en_route']);
        $entry = VisitTimeEntry::query()->firstOrFail();

        $this->actingAs($admin)->post("/office/visits/{$visit->id}/cancel", ['reason' => 'Schedule changed'])
            ->assertSessionHasErrors('confirm_stop_active_timers');
        $this->assertSame('en_route', $visit->fresh()->status);
        $this->assertNull($entry->fresh()->ended_at);

        $this->actingAs($admin)->post("/office/visits/{$visit->id}/cancel", [
            'reason' => 'Schedule changed',
            'confirm_stop_active_timers' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('canceled', $visit->fresh()->status);
        $this->assertNotNull($entry->fresh()->ended_at);
        $this->assertNull($entry->fresh()->active_user_id);
        $this->assertSame('system_auto', $entry->fresh()->source);
    }

    public function test_ticket_cancellation_stops_multiple_users_together_after_confirmation(): void
    {
        [$organization, $ticket, $first] = $this->ticketGraph('en_route');
        [$admin] = $this->userWithRole('super_admin', $organization);
        [$technician, , $membership] = $this->userWithRole('technician', $organization);
        $second = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $ticket->service_location_id,
            'status' => 'on_site',
            'timezone' => $organization->timezone,
        ]);
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $second->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);
        foreach ([[$first, $admin, 'travel'], [$second, $technician, 'on_site']] as [$visit, $user, $category]) {
            $closeout = $this->draft($visit, $user);
            VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $user->id, 'active_user_id' => $user->id, 'category' => $category, 'started_at' => now()->subHour()]);
        }

        $this->actingAs($admin)->post("/office/service-tickets/{$ticket->id}/transition", ['status' => 'canceled', 'reason' => 'Customer canceled'])
            ->assertSessionHasErrors('confirm_stop_active_timers');
        $this->actingAs($admin)->post("/office/service-tickets/{$ticket->id}/transition", ['status' => 'canceled', 'reason' => 'Customer canceled', 'confirm_stop_active_timers' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('canceled', $ticket->fresh()->status);
        $this->assertSame(2, VisitTimeEntry::query()->whereNotNull('ended_at')->where('source', 'system_auto')->count());
        $this->assertSame(0, VisitTimeEntry::query()->whereNotNull('active_user_id')->count());
    }

    public function test_execute_any_can_add_and_correct_assigned_crew_time_but_dispatcher_cannot(): void
    {
        [$organization, , $visit] = $this->ticketGraph('on_site');
        [$admin] = $this->userWithRole('super_admin', $organization);
        [$technician, , $membership] = $this->userWithRole('technician', $organization);
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);
        [$dispatcher] = $this->userWithRole('dispatcher', $organization);
        $payload = ['user_id' => $technician->id, 'category' => 'on_site', 'started_at' => '2026-07-30T09:00', 'ended_at' => '2026-07-30T10:00', 'correction_reason' => 'Entered from approved worksheet'];

        $this->actingAs($dispatcher)->post("/office/visits/{$visit->id}/execution/time", $payload)->assertForbidden();
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/execution/time", $payload)
            ->assertRedirectContains("execution_visit={$visit->id}")->assertSessionHasNoErrors();
        $entry = VisitTimeEntry::query()->firstOrFail();
        $this->assertSame($technician->id, $entry->user_id);

        $this->actingAs($admin)->post("/office/visits/{$visit->id}/execution/time", $payload)->assertSessionHasErrors('time');
        $this->actingAs($admin)->put("/office/visits/{$visit->id}/execution/time/{$entry->id}", [
            'started_at' => '2026-07-30T09:15', 'ended_at' => '2026-07-30T10:15', 'correction_reason' => 'Corrected worksheet transcription',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-07-30 14:15:00', $entry->fresh()->started_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_field_today_includes_current_canceled_and_all_authorized_past_seven_days(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 12:00:00', 'America/Chicago'));
        [$organization, $ticket, $today] = $this->ticketGraph('canceled');
        [$technician, , $membership] = $this->userWithRole('technician', $organization);
        $today->update(['scheduled_start_at' => CarbonImmutable::parse('2026-08-01 09:00', 'America/Chicago')->utc()]);
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $today->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);
        $past = $this->scheduledVisit($ticket, 'approved', '2026-07-28 10:00', $membership);
        $old = $this->scheduledVisit($ticket, 'canceled', '2026-07-24 10:00', $membership);
        $futureCanceled = $this->scheduledVisit($ticket, 'canceled', '2026-08-03 10:00', $membership);

        $response = $this->actingAs($technician)->get('/field');
        $response->assertOk()->assertSee('Past 7 days')->assertSee('Canceled')->assertSee($past->scheduledStartLocal()->format('D, M j'));
        $response->assertDontSee($old->scheduledStartLocal()->format('D, M j · g:i A T'))->assertDontSee($futureCanceled->scheduledStartLocal()->format('D, M j · g:i A T'));
        CarbonImmutable::setTestNow();
    }

    public function test_canceled_visit_is_read_only_except_for_completed_time_correction(): void
    {
        [$organization, , $visit] = $this->ticketGraph('canceled');
        [$admin] = $this->userWithRole('super_admin', $organization);
        $closeout = $this->draft($visit, $admin);
        $entry = VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $admin->id, 'category' => 'travel', 'started_at' => now()->subHour(), 'ended_at' => now(), 'source' => 'system_auto']);

        $this->actingAs($admin)->get("/field/visits/{$visit->id}")->assertOk()->assertSee('This visit was canceled.')->assertDontSee('Submit closeout');
        $this->actingAs($admin)->post("/field/visits/{$visit->id}/timer", ['action' => 'start', 'category' => 'travel'])->assertSessionHasErrors('visit');
        $this->actingAs($admin)->post("/field/visits/{$visit->id}/draft", ['content_version' => 1, 'outcome' => 'resolved'])->assertSessionHasErrors('visit');
        $this->actingAs($admin)->put("/field/visits/{$visit->id}/time/{$entry->id}", ['started_at' => '2026-07-30T09:00', 'ended_at' => '2026-07-30T10:00', 'correction_reason' => 'Canceled timer correction'])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function ticketGraph(string $status): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $contact = Contact::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'name' => 'Field Contact', 'is_preferred' => true, 'active' => true]);
        $location = ServiceLocation::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'primary_contact_id' => $contact->id, 'name' => 'Field Site', 'address_line_1' => '100 Main', 'city' => 'Fort Worth', 'state' => 'TX', 'postal_code' => '76102', 'timezone' => 'America/Chicago', 'is_primary' => true, 'active' => true]);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'contact_id' => $contact->id, 'ticket_number' => 'NDT-ST-2026-0001', 'title' => 'Office execution parity', 'description' => 'Restore service', 'priority' => 'normal', 'source' => 'phone', 'status' => 'open']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'status' => $status, 'timezone' => 'America/Chicago', 'scheduled_start_at' => now(), 'scheduled_end_at' => now()->addHour()]);

        return [$organization, $ticket, $visit];
    }

    private function draft(Visit $visit, User $user): Closeout
    {
        $closeout = Closeout::query()->create(['organization_id' => $visit->organization_id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'draft', 'content_version' => 1, 'last_saved_by_id' => $user->id]);
        $visit->update(['current_closeout_id' => $closeout->id]);

        return $closeout;
    }

    private function scheduledVisit(ServiceTicket $ticket, string $status, string $localStart, OrganizationMembership $membership): Visit
    {
        $start = CarbonImmutable::parse($localStart, 'America/Chicago');
        $visit = Visit::query()->create(['organization_id' => $ticket->organization_id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $ticket->service_location_id, 'status' => $status, 'timezone' => 'America/Chicago', 'scheduled_start_at' => $start->utc(), 'scheduled_end_at' => $start->addHour()->utc()]);
        VisitAssignment::query()->create(['organization_id' => $ticket->organization_id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);

        return $visit;
    }

    private function userWithRole(string $roleKey, Organization $organization): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }
}
