<?php

namespace Tests\Feature;

use App\Domain\ApprovedVisitLaborMinutes;
use App\Models\AuditEvent;
use App\Models\BillingHandoff;
use App\Models\Capability;
use App\Models\Closeout;
use App\Models\CloseoutReview;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmittedVisitTimeCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_exact_super_admin_corrects_submitted_time_without_mutating_raw_evidence(): void
    {
        [$organization, $ticket, $visit, $closeout, $entry] = $this->submittedGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);
        $originalStart = $entry->started_at->toISOString();
        $originalEnd = $entry->ended_at->toISOString();

        $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload('ticket', '2026-08-12T09:15', '2026-08-12T10:30', 'The captured clock interval was incorrect.'))
            ->assertRedirectContains("execution_visit={$visit->id}")
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame($originalStart, $entry->started_at->toISOString());
        $this->assertSame($originalEnd, $entry->ended_at->toISOString());
        $this->assertSame('2026-08-12 14:15:00', $entry->corrected_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-12 15:30:00', $entry->corrected_ended_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(4500, $entry->effectiveDurationSeconds());
        $this->assertDatabaseHas('visit_time_entry_corrections', ['visit_time_entry_id' => $entry->id, 'sequence' => 1, 'corrected_by_id' => $admin->id]);
        $event = AuditEvent::query()->where('event_type', 'visit_time.submitted_corrected')->firstOrFail();
        $this->assertSame(['corrected_started_at', 'corrected_ended_at'], $event->metadata['changed_fields']);
        $this->assertStringNotContainsString('captured clock', json_encode($event->metadata));

        $this->actingAs($admin)->get(route('office.service-tickets.show', $ticket))
            ->assertOk()->assertSee('Corrected submitted time')->assertSee('Correction history')->assertSee('Correct submitted time');
        $this->actingAs($admin)->get(route('office.closeout-reviews.show', $closeout))
            ->assertOk()->assertSee('75 minutes')->assertSee('value="75"', false)->assertSee('Correct submitted time');
    }

    public function test_correction_history_is_append_only_and_uses_the_previous_effective_interval(): void
    {
        [$organization, , , , $entry] = $this->submittedGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);

        $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload(reason: ''))
            ->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('visit_time_entry_corrections', 0);

        $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload('ticket', '2026-08-12T09:15', '2026-08-12T10:30', 'First correction.'));
        $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload('ticket', '2026-08-12T09:30', '2026-08-12T10:45', 'Second correction.'))
            ->assertSessionHasNoErrors();

        $history = $entry->corrections()->orderBy('sequence')->get();
        $this->assertSame([1, 2], $history->pluck('sequence')->all());
        $this->assertTrue($history[1]->previous_started_at->equalTo($history[0]->corrected_started_at));
        $this->assertTrue($history[1]->previous_ended_at->equalTo($history[0]->corrected_ended_at));

        $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload('ticket', '2026-08-12T09:30', '2026-08-12T10:45', 'No change.'))
            ->assertSessionHasErrors('time');
        $this->assertDatabaseCount('visit_time_entry_corrections', 2);
    }

    public function test_exact_role_capability_and_active_organization_are_all_required(): void
    {
        [$organization, , , , $entry] = $this->submittedGraph();
        $capability = Capability::query()->where('key', 'visit_time.correct_submitted')->firstOrFail();
        [$admin, , $adminMembership] = $this->userWithRole('super_admin', $organization);
        $adminMembership->capabilityOverrides()->attach($capability, ['effect' => 'deny']);
        $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload())->assertForbidden();

        foreach (['dispatcher', 'reviewer', 'billing', 'technician'] as $role) {
            [$user, , $membership] = $this->userWithRole($role, $organization);
            $membership->capabilityOverrides()->attach($capability, ['effect' => 'grant']);
            $membership->capabilityOverrides()->attach(Capability::query()->where('key', 'experience.office.access')->firstOrFail(), ['effect' => 'grant']);
            $response = $this->actingAs($user)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload());
            $response->assertForbidden();
        }

        [$inactive, , $inactiveMembership] = $this->userWithRole('super_admin', $organization);
        $inactiveMembership->update(['status' => 'inactive']);
        $this->actingAs($inactive)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload())->assertForbidden();

        $otherOrganization = Organization::factory()->create();
        [$outsider] = $this->userWithRole('super_admin', $otherOrganization);
        $this->actingAs($outsider)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload())->assertNotFound();
        $this->assertDatabaseCount('visit_time_entry_corrections', 0);
    }

    public function test_historical_submitted_entry_remains_correctable_through_return_and_resubmission(): void
    {
        [$organization, , $visit, $first, $entry] = $this->submittedGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);
        $second = Closeout::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $visit->id,
            'parent_closeout_id' => $first->id,
            'version' => 2,
            'status' => 'draft',
            'content_version' => 1,
            'last_saved_by_id' => $admin->id,
        ]);
        $visit->update(['current_closeout_id' => $second->id, 'status' => 'returned_for_correction']);

        $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload('review', '2026-08-12T09:10', '2026-08-12T10:10', 'Corrected while v2 draft.', $second->id))
            ->assertRedirect(route('office.closeout-reviews.show', $second))->assertSessionHasNoErrors();

        $second->update(['status' => 'submitted', 'submitted_at' => now(), 'submitted_token' => (string) Str::uuid()]);
        $visit->update(['status' => 'pending_closeout']);
        $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload('review', '2026-08-12T09:20', '2026-08-12T10:20', 'Corrected after v2 resubmission.', $second->id))
            ->assertRedirect(route('office.closeout-reviews.show', $second))->assertSessionHasNoErrors();

        $this->assertSame([1, 2], $entry->corrections()->pluck('sequence')->all());
        $this->assertSame($second->id, $visit->fresh()->current_closeout_id);
    }

    public function test_approval_completion_and_billing_handoff_each_block_submitted_correction(): void
    {
        foreach (['approval', 'completed', 'handoff', 'active_timer'] as $state) {
            [$organization, $ticket, $visit, $closeout, $entry] = $this->submittedGraph();
            [$admin] = $this->userWithRole('super_admin', $organization);
            if ($state === 'approval') {
                CloseoutReview::query()->create(['organization_id' => $organization->id, 'closeout_id' => $closeout->id, 'reviewer_id' => $admin->id, 'decision' => 'approved', 'decision_token' => (string) Str::uuid(), 'decided_at' => now()]);
                $visit->update(['status' => 'approved']);
            } elseif ($state === 'completed') {
                $ticket->update(['status' => 'completed']);
            } else {
                if ($state === 'handoff') {
                    BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'ready', 'created_by_id' => $admin->id]);
                } else {
                    $entry->update(['ended_at' => null, 'active_user_id' => $entry->user_id]);
                }
            }

            $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload())
                ->assertSessionHasErrors('time');
            $this->assertNull($entry->fresh()->corrected_started_at);
        }
    }

    public function test_effective_intervals_drive_overlap_and_preapproval_billing_minutes(): void
    {
        [$organization, $ticket, $visit, $closeout, $entry] = $this->submittedGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);
        $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload('ticket', '2026-08-12T10:00', '2026-08-12T11:30', 'Shifted to the actual interval.'))
            ->assertSessionHasNoErrors();

        $draft = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'parent_closeout_id' => $closeout->id, 'version' => 2, 'status' => 'draft', 'content_version' => 1, 'last_saved_by_id' => $admin->id]);
        $visit->update(['current_closeout_id' => $draft->id, 'status' => 'on_site']);
        $ownerMembership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $entry->user_id, 'status' => 'active']);
        $ownerMembership->roles()->attach(Role::query()->where('key', 'technician')->firstOrFail());
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $ownerMembership->id, 'is_lead' => true]);
        $allowed = ['user_id' => $entry->user_id, 'category' => 'other', 'started_at' => '2026-08-12T09:15', 'ended_at' => '2026-08-12T09:45', 'correction_reason' => 'Non-overlapping historical work.'];
        $this->actingAs($admin)->post(route('office.visits.execution.time.store', $visit), $allowed)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('office.visits.execution.time.store', $visit), array_merge($allowed, ['started_at' => '2026-08-12T10:30', 'ended_at' => '2026-08-12T11:00']))
            ->assertSessionHasErrors('time');

        $visit->update(['current_closeout_id' => $closeout->id, 'status' => 'pending_closeout']);
        CloseoutReview::query()->create(['organization_id' => $organization->id, 'closeout_id' => $closeout->id, 'reviewer_id' => $admin->id, 'decision' => 'approved', 'decision_token' => (string) Str::uuid(), 'decided_at' => now()]);
        $this->assertSame(120, app(ApprovedVisitLaborMinutes::class)->calculate($closeout));
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_approval_snapshots_effective_minutes_and_then_closes_the_correction_window(): void
    {
        [$organization, , $visit, $closeout, $entry] = $this->submittedGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload('ticket', '2026-08-12T09:00', '2026-08-12T10:30', 'The timer stopped thirty minutes early.'))
            ->assertSessionHasNoErrors();

        $this->actingAs($reviewer)->post(route('office.closeout-reviews.approve', $closeout), [
            'decision_token' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('billing_handoffs', [
            'service_ticket_id' => $visit->service_ticket_id,
            'approved_time_minutes' => 90,
        ]);
        $this->actingAs($admin)->put(route('office.visit-time-entries.submitted-correction.update', $entry), $this->payload('ticket', '2026-08-12T09:00', '2026-08-12T10:45', 'Late correction attempt.'))
            ->assertSessionHasErrors('time');
        $this->assertSame('2026-08-12 15:30:00', $entry->fresh()->corrected_ended_at->utc()->format('Y-m-d H:i:s'));
    }

    /** @return array{Organization, ServiceTicket, Visit, Closeout, VisitTimeEntry} */
    private function submittedGraph(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $owner = User::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'timezone' => 'America/Chicago']);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-9001', 'title' => 'Submitted time correction', 'priority' => 'normal', 'source' => 'phone', 'status' => 'open']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'status' => 'pending_closeout', 'timezone' => 'America/Chicago']);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'submitted', 'content_version' => 2, 'outcome' => 'resolved', 'diagnosis' => 'Diagnosis', 'work_performed' => 'Repair', 'submitted_token' => (string) Str::uuid(), 'submitted_by_id' => $owner->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        $entry = VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $owner->id, 'category' => 'on_site', 'started_at' => CarbonImmutable::parse('2026-08-12 09:00', 'America/Chicago')->utc(), 'ended_at' => CarbonImmutable::parse('2026-08-12 10:00', 'America/Chicago')->utc(), 'source' => 'timer']);

        return [$organization, $ticket, $visit, $closeout, $entry];
    }

    private function payload(string $context = 'ticket', string $start = '2026-08-12T09:15', string $end = '2026-08-12T10:15', string $reason = 'Clock interval correction.', ?int $reviewCloseoutId = null): array
    {
        return ['context' => $context, 'review_closeout_id' => $reviewCloseoutId, 'started_at' => $start, 'ended_at' => $end, 'reason' => $reason];
    }

    /** @return array{User, Organization, OrganizationMembership} */
    private function userWithRole(string $roleKey, Organization $organization): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }
}
