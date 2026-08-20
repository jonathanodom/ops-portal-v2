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
use App\Support\TimeConflictDiagnostic;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeConflictDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private int $ticketSequence = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_office_manual_overlap_identifies_the_cross_ticket_and_visit_entry_and_preserves_input(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$admin] = $this->userWithRole('super_admin', $organization);
        [$technician, $membership] = $this->userWithRole('technician', $organization, ['name' => 'Jane Smith']);
        $target = $this->visit($this->ticket($organization, 'NDT-ST-2026-0143'));
        $conflictTicket = $this->ticket($organization, 'NDT-ST-2026-0142');
        $this->visit($conflictTicket);
        $conflictVisit = $this->visit($conflictTicket);
        $this->assign($target, $membership);
        $this->timeEntry($conflictVisit, $technician, '2026-08-12 13:15', '2026-08-12 14:45', 'on_site', 'manual');
        $message = 'Time conflict: Jane Smith already has a Manual On-site entry from Aug 12, 1:15 PM–2:45 PM on Service Ticket NDT-ST-2026-0142, Visit 2.';
        $url = route('office.service-tickets.show', $target->service_ticket_id).'?execution_visit='.$target->id;

        $payload = [
            'time_form' => 'manual-'.$target->id,
            'user_id' => $technician->id,
            'category' => 'on_site',
            'started_at' => '2026-08-12T13:30',
            'ended_at' => '2026-08-12T14:00',
            'correction_reason' => 'Backfill from field notes',
        ];
        $response = $this->actingAs($admin)->from($url)->post(route('office.visits.execution.time.store', $target), $payload);

        $response->assertRedirect($url)->assertSessionHasErrors(['time' => $message]);
        $this->followingRedirects()->actingAs($admin)->from($url)->post(route('office.visits.execution.time.store', $target), $payload)
            ->assertOk()
            ->assertSee($message)
            ->assertSee('value="2026-08-12T13:30"', false)
            ->assertSee('Backfill from field notes');
    }

    public function test_active_timer_message_identifies_start_and_no_end_time(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$admin] = $this->userWithRole('super_admin', $organization);
        [$technician, $membership] = $this->userWithRole('technician', $organization, ['name' => 'Jane Smith']);
        $target = $this->visit($this->ticket($organization));
        $conflict = $this->visit($this->ticket($organization, 'NDT-ST-2026-0150'));
        $this->assign($target, $membership);
        $this->timeEntry($conflict, $technician, '2026-08-12 13:15', null, 'travel', 'timer', true);

        $this->actingAs($admin)->post(route('office.visits.execution.time.store', $target), [
            'user_id' => $technician->id,
            'category' => 'other',
            'started_at' => '2026-08-12T13:30',
            'ended_at' => '2026-08-12T14:00',
            'correction_reason' => 'Backfill',
        ])->assertSessionHasErrors([
            'time' => 'Time conflict: Jane Smith has an active Travel timer that started Aug 12 at 1:15 PM on Service Ticket NDT-ST-2026-0150, Visit 1 and has no end time.',
        ]);
    }

    public function test_adjacent_entries_and_a_different_user_are_allowed_but_a_real_overlap_is_rejected(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$admin] = $this->userWithRole('super_admin', $organization);
        [$technician, $membership] = $this->userWithRole('technician', $organization, ['name' => 'Jane Smith']);
        [$other, $otherMembership] = $this->userWithRole('technician', $organization);
        $target = $this->visit($this->ticket($organization));
        $otherVisit = $this->visit($this->ticket($organization));
        $this->assign($target, $membership);
        $this->assign($target, $otherMembership, false);
        $this->timeEntry($otherVisit, $technician, '2026-08-12 08:00', '2026-08-12 09:00');

        $this->actingAs($admin)->post(route('office.visits.execution.time.store', $target), $this->manualPayload($technician, '09:00', '10:00'))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('office.visits.execution.time.store', $target), $this->manualPayload($other, '08:30', '09:30'))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('office.visits.execution.time.store', $target), $this->manualPayload($technician, '08:30', '09:30'))
            ->assertSessionHasErrors('time');
    }

    public function test_correction_excludes_itself_and_then_identifies_another_conflicting_entry(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$admin] = $this->userWithRole('super_admin', $organization);
        $target = $this->visit($this->ticket($organization));
        $entry = $this->timeEntry($target, $admin, '2026-08-12 09:00', '2026-08-12 10:00');
        $url = route('office.service-tickets.show', $target->service_ticket_id).'?execution_visit='.$target->id;

        $this->actingAs($admin)->put(route('office.visits.execution.time.update', [$target, $entry]), [
            'started_at' => '2026-08-12T09:00', 'ended_at' => '2026-08-12T10:00', 'correction_reason' => 'Confirmed unchanged',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $otherVisit = $this->visit($this->ticket($organization, 'NDT-ST-2026-0160'));
        $this->timeEntry($otherVisit, $admin, '2026-08-12 10:15', '2026-08-12 11:15', 'other', 'system_auto');
        $payload = [
            'time_form' => 'correction-'.$entry->id,
            'started_at' => '2026-08-12T10:30',
            'ended_at' => '2026-08-12T11:00',
            'correction_reason' => 'Move to correct period',
        ];
        $response = $this->actingAs($admin)->from($url)->put(route('office.visits.execution.time.update', [$target, $entry]), $payload);

        $response->assertRedirect($url)->assertSessionHasErrors('time');
        $rendered = $this->followingRedirects()->actingAs($admin)->from($url)->put(route('office.visits.execution.time.update', [$target, $entry]), $payload);
        $rendered->assertOk()
            ->assertSee('System Other entry')
            ->assertSee('NDT-ST-2026-0160')
            ->assertSee('value="2026-08-12T10:30"', false)
            ->assertSee('Move to correct period');
        $this->assertMatchesRegularExpression('/<details class="mt-2"\s+open\s*>/', $rendered->getContent());
    }

    public function test_cross_organization_conflict_remains_enforced_without_leaking_context(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $foreignOrganization = Organization::factory()->create(['timezone' => 'America/New_York']);
        [$admin] = $this->userWithRole('super_admin', $organization);
        [$technician, $membership] = $this->userWithRole('technician', $organization, ['name' => 'Jane Smith']);
        $this->membership($technician, $foreignOrganization, 'technician');
        $target = $this->visit($this->ticket($organization));
        $foreignVisit = $this->visit($this->ticket($foreignOrganization, 'FOREIGN-SECRET-77'));
        $this->assign($target, $membership);
        $this->timeEntry($foreignVisit, $technician, '2026-08-12 13:15', '2026-08-12 14:45');

        $response = $this->actingAs($admin)->post(route('office.visits.execution.time.store', $target), [
            'user_id' => $technician->id, 'category' => 'on_site', 'started_at' => '2026-08-12T13:30',
            'ended_at' => '2026-08-12T14:00', 'correction_reason' => 'Backfill',
        ]);

        $response->assertSessionHasErrors([
            'time' => 'Time conflict: this user already has another time entry during this period. Details are unavailable because the entry belongs to another organization.',
        ]);
        $this->assertStringNotContainsString('FOREIGN-SECRET-77', session('errors')->first('time'));
    }

    public function test_field_correction_is_safe_actionable_and_preserves_the_technicians_input(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$technician, $membership] = $this->userWithRole('technician', $organization, ['name' => 'Jane Smith']);
        $target = $this->visit($this->ticket($organization));
        $conflict = $this->visit($this->ticket($organization, 'NDT-ST-2026-0170'));
        $this->assign($target, $membership);
        $entry = $this->timeEntry($target, $technician, '2026-08-12 08:00', '2026-08-12 09:00');
        $this->timeEntry($conflict, $technician, '2026-08-12 10:00', '2026-08-12 11:00', 'on_site', 'manual');
        $url = route('field.visits.show', $target);

        $payload = [
            'time_form' => 'field-correction-'.$entry->id,
            'started_at' => '2026-08-12T10:15',
            'ended_at' => '2026-08-12T10:45',
            'correction_reason' => 'Correcting field record',
        ];
        $response = $this->actingAs($technician)->from($url)->put(route('field.visits.time.update', [$target, $entry]), $payload);

        $response->assertRedirect($url)->assertSessionHasErrors('time');
        $this->followingRedirects()->actingAs($technician)->from($url)->put(route('field.visits.time.update', [$target, $entry]), $payload)
            ->assertOk()
            ->assertSee('NDT-ST-2026-0170')
            ->assertSee('value="2026-08-12T10:15"', false)
            ->assertSee('Correcting field record')
            ->assertDontSee('office/service-tickets');
    }

    public function test_missing_optional_diagnostic_relationships_degrade_safely(): void
    {
        $context = new Visit(['organization_id' => 91, 'timezone' => null]);
        $context->id = 501;
        $conflict = new VisitTimeEntry([
            'organization_id' => 91,
            'category' => 'other',
            'source' => 'system_auto',
            'started_at' => CarbonImmutable::parse('2026-08-12 18:15:00', 'UTC'),
            'ended_at' => CarbonImmutable::parse('2026-08-12 19:15:00', 'UTC'),
        ]);
        $context->setRelation('serviceTicket', null);
        $conflict->setRelation('user', null)->setRelation('visit', null);

        $message = app(TimeConflictDiagnostic::class)->message($conflict, $context);

        $this->assertStringContainsString('This user already has a System Other entry', $message);
        $this->assertStringContainsString('an unavailable Service Ticket, Visit unavailable', $message);
    }

    public function test_existing_office_authorization_is_unchanged(): void
    {
        $organization = Organization::factory()->create();
        [$dispatcher] = $this->userWithRole('dispatcher', $organization);
        $target = $this->visit($this->ticket($organization));

        $this->actingAs($dispatcher)->post(route('office.visits.execution.time.store', $target), [
            'user_id' => $dispatcher->id, 'category' => 'other', 'started_at' => '2026-08-12T09:00',
            'ended_at' => '2026-08-12T10:00', 'correction_reason' => 'Backfill',
        ])->assertForbidden();
    }

    private function ticket(Organization $organization, ?string $number = null): ServiceTicket
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $contact = Contact::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'name' => 'Field Contact', 'is_preferred' => true, 'active' => true]);
        $location = ServiceLocation::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'primary_contact_id' => $contact->id, 'name' => 'Field Site', 'address_line_1' => '100 Main', 'city' => 'Fort Worth', 'state' => 'TX', 'postal_code' => '76102', 'timezone' => $organization->timezone, 'is_primary' => true, 'active' => true]);

        return ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'contact_id' => $contact->id, 'ticket_number' => $number ?? 'NDT-ST-2026-'.str_pad((string) $this->ticketSequence++, 4, '0', STR_PAD_LEFT), 'title' => 'Time diagnostics', 'description' => 'Restore service', 'priority' => 'normal', 'source' => 'phone', 'status' => 'open']);
    }

    private function visit(ServiceTicket $ticket): Visit
    {
        return Visit::query()->create(['organization_id' => $ticket->organization_id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $ticket->service_location_id, 'status' => 'on_site', 'timezone' => $ticket->organization->timezone]);
    }

    private function timeEntry(Visit $visit, User $user, string $localStart, ?string $localEnd, string $category = 'on_site', string $source = 'manual', bool $active = false): VisitTimeEntry
    {
        $closeout = $visit->currentCloseout ?: Closeout::query()->create(['organization_id' => $visit->organization_id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'draft', 'content_version' => 1, 'last_saved_by_id' => $user->id]);
        $visit->update(['current_closeout_id' => $closeout->id]);

        return VisitTimeEntry::query()->create([
            'organization_id' => $visit->organization_id,
            'visit_id' => $visit->id,
            'closeout_id' => $closeout->id,
            'user_id' => $user->id,
            'active_user_id' => $active ? $user->id : null,
            'category' => $category,
            'started_at' => CarbonImmutable::parse($localStart, $visit->timezone)->utc(),
            'ended_at' => $localEnd ? CarbonImmutable::parse($localEnd, $visit->timezone)->utc() : null,
            'source' => $source,
        ]);
    }

    private function manualPayload(User $user, string $start, string $end): array
    {
        return ['user_id' => $user->id, 'category' => 'on_site', 'started_at' => '2026-08-12T'.$start, 'ended_at' => '2026-08-12T'.$end, 'correction_reason' => 'Backfill'];
    }

    private function assign(Visit $visit, OrganizationMembership $membership, bool $lead = true): void
    {
        VisitAssignment::query()->create(['organization_id' => $visit->organization_id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => $lead]);
    }

    private function userWithRole(string $role, Organization $organization, array $attributes = []): array
    {
        $user = User::factory()->create($attributes + ['status' => 'active']);

        return [$user, $this->membership($user, $organization, $role)];
    }

    private function membership(User $user, Organization $organization, string $role): OrganizationMembership
    {
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

        return $membership;
    }
}
