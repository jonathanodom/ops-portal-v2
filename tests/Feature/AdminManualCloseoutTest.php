<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\BillingHandoff;
use App\Models\Capability;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTimeEntry;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminManualCloseoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_only_super_admin_receives_manual_completion_by_default_and_overrides_apply(): void
    {
        [$organization, $ticket, $visit] = $this->ticketGraph();
        [$admin, , $adminMembership] = $this->userWithRole('super_admin', $organization);
        [$dispatcher, , $dispatcherMembership] = $this->userWithRole('dispatcher', $organization);

        $this->actingAs($admin)->get("/office/service-tickets/{$ticket->id}")->assertOk()->assertSee('Start manual closeout');
        $this->actingAs($dispatcher)->post("/office/visits/{$visit->id}/manual-closeout/start")->assertForbidden();

        $capability = Capability::query()->where('key', 'closeouts.manual_complete')->firstOrFail();
        $dispatcherMembership->capabilityOverrides()->attach($capability, ['effect' => 'grant']);
        $this->actingAs($dispatcher)->post("/office/visits/{$visit->id}/manual-closeout/start")->assertRedirect()->assertSessionHasNoErrors();
        $adminMembership->capabilityOverrides()->attach($capability, ['effect' => 'deny']);
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/start")->assertForbidden();

        [$inactiveAdmin, , $inactiveMembership] = $this->userWithRole('super_admin', $organization);
        $inactiveMembership->update(['status' => 'inactive']);
        $this->actingAs($inactiveAdmin)->post("/office/visits/{$visit->id}/manual-closeout/start")->assertForbidden();
    }

    public function test_manual_completion_creates_one_immutable_closeout_review_completion_and_handoff(): void
    {
        [$organization, $ticket, $visit] = $this->ticketGraph('on_site');
        [$admin] = $this->userWithRole('super_admin', $organization);
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/start")->assertRedirect()->assertSessionHasNoErrors();
        $closeout = $visit->fresh()->currentCloseout;
        $this->actingAs($admin)->get("/office/service-tickets/{$ticket->id}?manual_closeout_visit={$visit->id}")
            ->assertOk()->assertSee('Administrative closeout')->assertSee('sm:h-[92dvh]', false)->assertSee('sm:w-[96vw]', false);
        $token = (string) Str::uuid();
        $payload = $this->completionPayload($closeout, $token);

        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/complete", $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $review = $closeout->fresh()->reviews()->firstOrFail();
        $this->assertSame('submitted', $closeout->fresh()->status);
        $this->assertSame('resolved', $closeout->fresh()->outcome);
        $this->assertSame('approved', $visit->fresh()->status);
        $this->assertSame('completed', $ticket->fresh()->status);
        $this->assertTrue($review->administrative_completion);
        $this->assertTrue($review->self_review_override);
        $this->assertSame('Administrative reconciliation', $review->administrative_completion_reason);
        $this->assertNotNull($review->administratively_completed_at);
        $this->assertDatabaseHas('billing_handoffs', ['service_ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'ready']);

        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/complete", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $closeout->reviews()->count());
        $this->assertSame(1, BillingHandoff::query()->where('service_ticket_id', $ticket->id)->count());

        $audit = AuditEvent::query()->where('event_type', 'closeout.administratively_completed')->firstOrFail();
        $this->assertArrayNotHasKey('administrative_completion_reason', $audit->metadata);
        $this->assertStringNotContainsString('Administrative reconciliation', json_encode($audit->metadata));
    }

    public function test_manual_closeout_photo_controls_offer_camera_and_gallery_sources(): void
    {
        [$organization, $ticket, $visit] = $this->ticketGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);
        $this->actingAs($admin)->post(route('office.visits.manual-closeout.start', $visit))->assertRedirect();

        $response = $this->actingAs($admin)->get(route('office.service-tickets.show', [
            'serviceTicket' => $ticket,
            'manual_closeout_visit' => $visit->id,
        ]));

        $response->assertOk()
            ->assertSee('Take photo')
            ->assertSee('Choose from gallery or files')
            ->assertSee('data-upload-photo-source', false)
            ->assertSee('data-upload-selection', false)
            ->assertSee('capture="environment"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'capture="environment"'));
    }

    public function test_manual_completion_requires_standard_resolved_evidence_and_confirmation(): void
    {
        [$organization, , $visit] = $this->ticketGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/start");
        $closeout = $visit->fresh()->currentCloseout;

        $payload = $this->completionPayload($closeout, (string) Str::uuid());
        unset($payload['diagnosis'], $payload['no_photo_category'], $payload['no_photo_detail']);
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/complete", $payload)
            ->assertSessionHasErrors(['diagnosis', 'no_photo_category']);
        $this->assertSame('draft', $closeout->fresh()->status);

        $payload = $this->completionPayload($closeout->fresh(), (string) Str::uuid());
        unset($payload['confirm_administrative_completion']);
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/complete", $payload)
            ->assertSessionHasErrors('confirm_administrative_completion');
    }

    public function test_ticket_timers_other_unfinished_visits_and_invalid_states_block_manual_completion(): void
    {
        [$organization, $ticket, $visit] = $this->ticketGraph('on_site');
        [$admin] = $this->userWithRole('super_admin', $organization);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'draft', 'content_version' => 1]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $admin->id, 'active_user_id' => $admin->id, 'category' => 'on_site', 'started_at' => now()]);
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/start")->assertSessionHasErrors('active_timers');

        VisitTimeEntry::query()->update(['ended_at' => now(), 'active_user_id' => null]);
        $other = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $visit->service_location_id, 'status' => 'assigned', 'timezone' => 'America/Chicago']);
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/start")->assertSessionHasErrors('other_visits');

        $other->delete();
        $ticket->update(['status' => 'canceled']);
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/start")->assertSessionHasErrors('ticket');
    }

    public function test_existing_draft_time_media_and_parts_are_reused_and_stale_saves_are_rejected(): void
    {
        Storage::fake('local');
        [$organization, , $visit] = $this->ticketGraph('assigned');
        [$admin] = $this->userWithRole('super_admin', $organization);
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/start");
        $closeout = $visit->fresh()->currentCloseout;
        VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $admin->id, 'category' => 'other', 'started_at' => now()->subHour(), 'ended_at' => now(), 'source' => 'manual']);

        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/media", [
            'photo' => UploadedFile::fake()->image('after.jpg'),
            'category' => 'after',
        ])->assertCreated();
        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/parts", [
            'description' => 'Replacement module', 'quantity' => 1, 'unit' => 'each', 'billing_treatment' => 'billable',
        ])->assertRedirect();

        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/draft", [
            'content_version' => $closeout->content_version,
            'diagnosis' => 'Saved from office',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->postJson("/office/visits/{$visit->id}/manual-closeout/draft", [
            'content_version' => $closeout->content_version,
            'diagnosis' => 'Stale overwrite',
        ])->assertConflict()->assertJsonPath('content_version', 2)->assertJsonPath('message', 'This closeout changed in another session. Your entries remain on screen; review the warning before explicitly retrying.');

        $this->assertSame($closeout->id, $visit->fresh()->current_closeout_id);
        $this->assertSame('Saved from office', $closeout->fresh()->diagnosis);
        $this->assertSame(1, $closeout->timeEntries()->count());
        $this->assertSame(1, $closeout->media()->where('state', 'stored')->count());
        $this->assertSame(1, $closeout->parts()->whereNull('removed_at')->count());
    }

    public function test_manual_closeout_urls_are_organization_scoped(): void
    {
        [$organization, , $visit] = $this->ticketGraph();
        [$foreign] = $this->ticketGraph();
        [$admin] = $this->userWithRole('super_admin', $foreign);

        $this->actingAs($admin)->post("/office/visits/{$visit->id}/manual-closeout/start")->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['organization_id' => $foreign->id, 'event_type' => 'security.cross_organization_record_denied']);
    }

    /** @return array{Organization, ServiceTicket, Visit} */
    private function ticketGraph(string $status = 'assigned'): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'timezone' => 'America/Chicago']);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-'.str_pad((string) $organization->id, 4, '0', STR_PAD_LEFT), 'title' => 'Administrative closeout test', 'description' => 'Test scope', 'priority' => 'normal', 'source' => 'internal', 'status' => 'open']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'status' => $status, 'timezone' => 'America/Chicago']);

        return [$organization, $ticket, $visit];
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

    /** @return array<string, mixed> */
    private function completionPayload(Closeout $closeout, string $token): array
    {
        return [
            'content_version' => $closeout->content_version,
            'completion_token' => $token,
            'diagnosis' => 'Administrative diagnosis',
            'work_performed' => 'Administrative work completed',
            'ack_unavailable_category' => 'remote_service',
            'ack_unavailable_detail' => 'Customer was not present',
            'no_photo_category' => 'not_applicable',
            'no_photo_detail' => 'No useful visual evidence',
            'administrative_completion_reason' => 'Administrative reconciliation',
            'confirm_administrative_completion' => 1,
        ];
    }
}
