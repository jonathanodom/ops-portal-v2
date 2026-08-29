<?php

namespace Tests\Feature;

use App\Jobs\DeleteRemovedVisitMedia;
use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\Closeout;
use App\Models\CloseoutAcknowledgmentSignature;
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
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileFieldExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_assigned_crew_share_one_lazily_created_draft_and_stale_saves_conflict(): void
    {
        [$organization, $visit, $lead, $crew] = $this->executionGraph();

        $this->actingAs($lead)->post("/field/visits/{$visit->id}/draft", [
            'content_version' => 1,
            'outcome' => 'resolved',
            'diagnosis' => 'Signal loss',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $closeout = Closeout::query()->firstOrFail();
        $this->assertSame($closeout->id, $visit->fresh()->current_closeout_id);
        $this->assertSame(2, $closeout->fresh()->content_version);

        $this->actingAs($crew)->post("/field/visits/{$visit->id}/draft", [
            'content_version' => 1,
            'outcome' => 'resolved',
            'diagnosis' => 'Stale overwrite',
        ])->assertStatus(409)->assertSee('Your submitted values remain')->assertSee('Stale overwrite');

        $this->assertSame('Signal loss', $closeout->fresh()->diagnosis);
        $this->assertDatabaseCount('closeouts', 1);
        $this->assertDatabaseHas('audit_events', ['organization_id' => $organization->id, 'event_type' => 'closeout.draft_saved']);
    }

    public function test_transitions_capture_individual_time_and_enforce_one_active_timer(): void
    {
        [, $visit, $lead] = $this->executionGraph('assigned');

        $this->actingAs($lead)->post("/field/visits/{$visit->id}/transition", ['status' => 'en_route'])->assertRedirect();
        $travel = VisitTimeEntry::query()->firstOrFail();
        $this->assertSame('travel', $travel->category);
        $this->assertNull($travel->ended_at);

        $this->actingAs($lead)->post("/field/visits/{$visit->id}/timer", ['action' => 'start', 'category' => 'other'])
            ->assertSessionHasErrors('time');

        $this->actingAs($lead)->post("/field/visits/{$visit->id}/transition", ['status' => 'on_site'])->assertRedirect();
        $this->assertNotNull($travel->fresh()->ended_at);
        $this->assertDatabaseHas('visit_time_entries', ['visit_id' => $visit->id, 'user_id' => $lead->id, 'category' => 'on_site', 'active_user_id' => $lead->id]);
    }

    public function test_closeout_action_is_context_aware_compact_and_absent_before_on_site_or_after_submission(): void
    {
        [, $visit, $lead] = $this->executionGraph('assigned');

        $this->actingAs($lead)->get(route('field.visits.show', $visit))
            ->assertOk()
            ->assertSee('Start En Route')
            ->assertDontSee('data-closeout-action-footer', false)
            ->assertDontSee('data-closeout-dialog', false);

        $visit->update(['status' => 'en_route']);
        $this->actingAs($lead)->get(route('field.visits.show', $visit))
            ->assertOk()
            ->assertSee('Mark On Site')
            ->assertDontSee('data-closeout-action-footer', false);

        $visit->update(['status' => 'canceled']);
        $this->actingAs($lead)->get(route('field.visits.show', $visit))
            ->assertOk()
            ->assertDontSee('data-closeout-action-footer', false);

        $visit->update(['status' => 'on_site']);
        $this->actingAs($lead)->get(route('field.visits.show', $visit))
            ->assertOk()
            ->assertSee('data-closeout-action-footer', false)
            ->assertSee('data-closeout-dialog', false)
            ->assertSee('1 item missing')
            ->assertSee('>Review<', false);

        $this->actingAs($lead)->post(route('field.visits.draft', $visit), $this->resolvedDraft())
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->actingAs($lead)->get(route('field.visits.show', $visit))
            ->assertOk()
            ->assertSee('Resolved - Ready for final review')
            ->assertSee('>Final review<', false)
            ->assertSee('Submit closeout')
            ->assertSee('data-closeout-dialog-close', false);

        $this->actingAs($lead)->post(route('field.visits.submit', $visit), ['submission_token' => (string) Str::uuid()])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->actingAs($lead)->get(route('field.visits.show', $visit->fresh()))
            ->assertOk()
            ->assertDontSee('data-closeout-action-footer', false)
            ->assertDontSee('data-closeout-dialog', false);
    }

    public function test_lead_only_submission_validates_evidence_stops_timers_and_is_idempotent(): void
    {
        [, $visit, $lead, $crew] = $this->executionGraph('on_site');
        $this->actingAs($crew)->post("/field/visits/{$visit->id}/draft", $this->resolvedDraft())->assertRedirect();
        $closeout = $visit->fresh()->currentCloseout;
        $this->actingAs($crew)->post("/field/visits/{$visit->id}/timer", ['action' => 'start', 'category' => 'on_site'])->assertRedirect();
        $token = (string) Str::uuid();

        $response = $this->actingAs($crew)->post("/field/visits/{$visit->id}/submit", ['submission_token' => $token]);
        $this->assertSame(403, $response->getStatusCode());
        $this->actingAs($lead)->post("/field/visits/{$visit->id}/submit", ['submission_token' => $token])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('submitted', $closeout->fresh()->status);
        $this->assertSame('pending_closeout', $visit->fresh()->status);
        $this->assertDatabaseMissing('visit_time_entries', ['closeout_id' => $closeout->id, 'ended_at' => null]);
        $this->actingAs($lead)->post("/field/visits/{$visit->id}/submit", ['submission_token' => $token])->assertRedirect();
        $this->assertDatabaseCount('closeouts', 1);
        $this->actingAs($lead)->post("/field/visits/{$visit->id}/draft", $this->resolvedDraft(2))->assertSessionHasErrors('closeout');
    }

    public function test_submission_errors_render_beside_each_required_closeout_field(): void
    {
        [, $visit, $lead] = $this->executionGraph('on_site');
        $this->actingAs($lead)->post("/field/visits/{$visit->id}/draft", [
            'content_version' => 1,
            'outcome' => 'customer_unavailable',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->from("/field/visits/{$visit->id}")->followingRedirects()->post("/field/visits/{$visit->id}/submit", [
            'submission_token' => (string) Str::uuid(),
        ])
            ->assertOk()
            ->assertSee('id="unavailable_category-error"', false)
            ->assertSee('id="unavailable_detail-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('Choose a customer unavailable reason.')
            ->assertSee('Customer unavailable details are required.');
    }

    public function test_on_site_acknowledgment_requires_and_stores_immutable_private_signature_evidence(): void
    {
        Storage::fake('local');
        [$organization, $visit, $lead] = $this->executionGraph('on_site');
        $draft = $this->resolvedDraft();
        unset($draft['ack_unavailable_category'], $draft['ack_unavailable_detail']);
        $draft['representative_name'] = 'Taylor Customer';
        $draft['representative_role'] = 'Site manager';
        $this->actingAs($lead)->post(route('field.visits.draft', $visit), $draft)->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($lead)->post(route('field.visits.submit', $visit), [
            'submission_token' => (string) Str::uuid(),
            'acknowledgment_confirmed' => '1',
        ])->assertSessionHasErrors('signature_data');

        $this->actingAs($lead)->post(route('field.visits.submit', $visit), [
            'submission_token' => (string) Str::uuid(),
            'acknowledgment_confirmed' => '1',
            'signature_data' => $this->signaturePng(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $signature = CloseoutAcknowledgmentSignature::query()->firstOrFail();
        $this->assertSame($organization->id, $signature->organization_id);
        $this->assertSame('Taylor Customer', $signature->signer_name);
        $this->assertSame('Site manager', $signature->signer_role);
        $this->assertStringNotContainsString('Taylor', $signature->storage_key);
        $this->assertMatchesRegularExpression('#^field-acknowledgments/\d{4}/\d{2}/[0-9a-f-]{36}\.png$#', $signature->storage_key);
        Storage::disk('local')->assertExists($signature->storage_key);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($signature->storage_key)), $signature->sha256);
        $this->actingAs($lead)->get(route('closeout-acknowledgment-signatures.show', $signature))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $this->actingAs($reviewer)->get(route('office.closeout-reviews.show', $signature->closeout_id))
            ->assertOk()->assertSee('Signed on-site')->assertSee('View signature evidence');
        [$billing] = $this->userWithRole('billing', $organization);
        $this->actingAs($billing)->get(route('closeout-acknowledgment-signatures.show', $signature))->assertForbidden();
        [$outsider] = $this->userWithRole('super_admin');
        $this->actingAs($outsider)->get(route('closeout-acknowledgment-signatures.show', $signature))->assertNotFound();
        $audit = AuditEvent::query()->where('event_type', 'closeout.customer_acknowledgment_signed')->firstOrFail();
        $this->assertTrue($audit->metadata['signer_name_present']);
        $this->assertArrayNotHasKey('storage_key', $audit->metadata);
        $this->assertStringNotContainsString('Taylor Customer', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_acknowledgment_fallback_needs_no_signature_and_signature_pad_rejects_blank_or_mixed_submission(): void
    {
        Storage::fake('local');
        [, $visit, $lead] = $this->executionGraph('on_site');
        $this->actingAs($lead)->post(route('field.visits.draft', $visit), $this->resolvedDraft())->assertRedirect();
        $this->actingAs($lead)->post(route('field.visits.submit', $visit), [
            'submission_token' => (string) Str::uuid(),
            'signature_data' => $this->signaturePng(),
        ])->assertSessionHasErrors('signature_data');

        $otherVisit = $this->additionalAssignedVisit($visit, $lead, 'on_site');
        $draft = $this->resolvedDraft();
        unset($draft['ack_unavailable_category'], $draft['ack_unavailable_detail']);
        $draft['representative_name'] = 'Blank Test';
        $this->actingAs($lead)->post(route('field.visits.draft', $otherVisit), $draft)->assertRedirect();
        $this->actingAs($lead)->post(route('field.visits.submit', $otherVisit), [
            'submission_token' => (string) Str::uuid(),
            'acknowledgment_confirmed' => '1',
            'signature_data' => $this->signaturePng(false),
        ])->assertSessionHasErrors('signature_data');
    }

    public function test_saved_readiness_errors_map_to_exact_fields_and_actionable_review_items(): void
    {
        [, $visit, $lead] = $this->executionGraph('on_site');
        $this->actingAs($lead)->post(route('field.visits.draft', $visit), [
            'content_version' => 1,
            'outcome' => 'needs_return_trip',
            'diagnosis' => 'Additional work is required.',
            'work_performed' => 'Made the system safe for a return visit.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($lead)->get(route('field.visits.show', $visit))
            ->assertOk()
            ->assertSee('data-closeout-field="return_reason"', false)
            ->assertSee('id="return_reason"', false)
            ->assertSee('name="return_reason"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="return_reason-error"', false)
            ->assertSee('id="return_reason-error"', false)
            ->assertSee('data-closeout-fix-target="return_reason"', false)
            ->assertSee('data-closeout-fix-target="unfinished_work"', false)
            ->assertSee('data-closeout-fix-target="needed_equipment"', false)
            ->assertSee('data-closeout-fix-target="recommendations"', false)
            ->assertSee('Required for a return trip.');
    }

    public function test_all_submission_outcomes_apply_their_atomic_effects(): void
    {
        [$organization, $returnVisit, $lead] = $this->executionGraph('on_site');
        $this->actingAs($lead)->post("/field/visits/{$returnVisit->id}/draft", $this->returnDraft())->assertRedirect()->assertSessionHasNoErrors();
        $token = (string) Str::uuid();
        $this->actingAs($lead)->post("/field/visits/{$returnVisit->id}/submit", ['submission_token' => $token])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($lead)->post("/field/visits/{$returnVisit->id}/submit", ['submission_token' => $token])->assertRedirect();
        $this->assertDatabaseCount('visits', 2);
        $this->assertDatabaseHas('visits', ['return_of_visit_id' => $returnVisit->id, 'status' => 'planned']);

        $holdVisit = $this->additionalAssignedVisit($returnVisit, $lead, 'on_site');
        $this->actingAs($lead)->post("/field/visits/{$holdVisit->id}/draft", $this->holdDraft())->assertRedirect();
        $this->actingAs($lead)->post("/field/visits/{$holdVisit->id}/submit", ['submission_token' => (string) Str::uuid()])->assertRedirect();
        $this->assertSame('on_hold', $holdVisit->serviceTicket->fresh()->status);

        $holdVisit->serviceTicket->update(['status' => 'open']);
        $unavailableVisit = $this->additionalAssignedVisit($returnVisit, $lead, 'on_site');
        $this->actingAs($lead)->post("/field/visits/{$unavailableVisit->id}/draft", [
            'content_version' => 1,
            'outcome' => 'customer_unavailable',
            'unavailable_category' => 'no_answer',
            'unavailable_detail' => 'No authorized representative on site',
        ])->assertRedirect();
        $this->actingAs($lead)->post("/field/visits/{$unavailableVisit->id}/submit", ['submission_token' => (string) Str::uuid()])->assertRedirect();
        $this->assertSame('customer_unavailable', $unavailableVisit->fresh()->status);
        $this->assertSame('open', $unavailableVisit->serviceTicket->fresh()->status);
        $this->assertStringNotContainsString('No authorized representative', AuditEvent::query()->where('organization_id', $organization->id)->get()->pluck('metadata')->toJson());
    }

    public function test_private_media_is_opaque_authorized_soft_removed_and_queued_for_cleanup(): void
    {
        Storage::fake('local');
        Queue::fake();
        [, $visit, $lead] = $this->executionGraph('on_site');
        [$billing] = $this->userWithRole('billing', $visit->serviceTicket->organization);

        $response = $this->actingAs($lead)->post("/field/visits/{$visit->id}/media", [
            'category' => 'before',
            'photo' => UploadedFile::fake()->image('customer-filename.jpg'),
        ]);
        $response->assertCreated();
        $media = $visit->fresh()->currentCloseout->media()->firstOrFail();
        $this->assertStringNotContainsString('customer-filename', $media->storage_key);
        Storage::disk('local')->assertExists($media->storage_key);

        $this->actingAs($billing)->get("/field-media/{$media->id}")->assertForbidden();
        $this->actingAs($lead)->delete("/field/visits/{$visit->id}/media/{$media->id}")->assertRedirect();
        $this->assertSame('removed', $media->fresh()->state);
        Queue::assertPushed(DeleteRemovedVisitMedia::class, fn ($job) => $job->mediaId === $media->id);
    }

    public function test_field_photo_upload_offers_separate_camera_and_gallery_file_sources(): void
    {
        [, $visit, $lead] = $this->executionGraph('on_site');

        $response = $this->actingAs($lead)->get(route('field.visits.show', $visit));
        $response->assertOk()
            ->assertSee('Take photo')
            ->assertSee('Choose from gallery or files')
            ->assertSee('data-upload-photo-source', false)
            ->assertSee('data-upload-selection', false);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'capture="environment"'));
        $this->assertSame(2, substr_count($html, 'accept="image/jpeg,image/png,image/webp,image/heic,image/heif"'));
        $this->assertMatchesRegularExpression('/<input[^>]+id="photo_camera"[^>]+capture="environment"[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+id="photo_library"[^>]+name="photo"[^>]*>/', $html);
        preg_match('/<input[^>]+id="photo_library"[^>]*>/', $html, $libraryInput);
        $this->assertStringNotContainsString('capture=', $libraryInput[0]);

        $visit->update(['status' => 'returned_for_correction']);
        $this->actingAs($lead)->get(route('field.visits.show', $visit))
            ->assertOk()
            ->assertSee('Take photo')
            ->assertSee('Choose from gallery or files');
    }

    public function test_field_photo_upload_rejects_missing_invalid_and_oversized_files(): void
    {
        Storage::fake('local');
        [, $visit, $lead] = $this->executionGraph('on_site');
        $endpoint = route('field.visits.media.store', $visit);

        $this->actingAs($lead)->postJson($endpoint, ['category' => 'before'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        $this->actingAs($lead)->postJson($endpoint, [
            'category' => 'before',
            'photo' => UploadedFile::fake()->create('not-an-image.txt', 10, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors('photo');

        $this->actingAs($lead)->postJson($endpoint, [
            'category' => 'before',
            'photo' => UploadedFile::fake()->image('too-large.jpg')->size(config('field_execution.max_photo_kb') + 1),
        ])->assertUnprocessable()->assertJsonValidationErrors('photo');

        $this->assertDatabaseCount('visit_media', 0);
    }

    public function test_office_draft_projection_hides_narrative_and_submitted_evidence_respects_inspection_capability(): void
    {
        [, $visit, $lead] = $this->executionGraph('on_site');
        [$reviewer] = $this->userWithRole('reviewer', $visit->serviceTicket->organization);
        [$billing] = $this->userWithRole('billing', $visit->serviceTicket->organization);
        $this->actingAs($lead)->post("/field/visits/{$visit->id}/draft", $this->resolvedDraft())->assertRedirect();

        $this->actingAs($reviewer)->get("/office/service-tickets/{$visit->service_ticket_id}")
            ->assertOk()->assertSee('Field closeout: Draft')->assertDontSee('Sensitive diagnosis');

        $this->actingAs($lead)->post("/field/visits/{$visit->id}/submit", ['submission_token' => (string) Str::uuid()])->assertRedirect();
        $this->actingAs($reviewer)->get("/office/service-tickets/{$visit->service_ticket_id}")->assertOk()->assertSee('Sensitive diagnosis');
        $this->actingAs($billing)->get("/office/service-tickets/{$visit->service_ticket_id}")->assertOk()->assertDontSee('Sensitive diagnosis');
    }

    public function test_submission_requires_resolved_evidence_and_explicit_execute_any_is_audited(): void
    {
        [$organization, $visit] = $this->executionGraph('on_site');
        [$dispatcher, , $membership] = $this->userWithRole('dispatcher', $organization);
        $membership->capabilityOverrides()->attach(Capability::query()->where('key', 'visits.execute_any')->firstOrFail(), ['effect' => 'grant']);

        $this->actingAs($dispatcher)->post("/field/visits/{$visit->id}/draft", [
            'content_version' => 1,
            'outcome' => 'resolved',
            'diagnosis' => 'Fault located',
            'work_performed' => 'Service restored',
            'ack_unavailable_category' => 'remote_service',
            'ack_unavailable_detail' => 'Confirmed remotely',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $token = (string) Str::uuid();
        $this->actingAs($dispatcher)->post("/field/visits/{$visit->id}/submit", ['submission_token' => $token])
            ->assertSessionHasErrors('no_photo_category');

        $this->actingAs($dispatcher)->post("/field/visits/{$visit->id}/draft", [
            'content_version' => 2,
            'outcome' => 'resolved',
            'diagnosis' => 'Fault located',
            'work_performed' => 'Service restored',
            'ack_unavailable_category' => 'remote_service',
            'ack_unavailable_detail' => 'Confirmed remotely',
            'no_photo_category' => 'not_applicable',
            'no_photo_detail' => 'No visible component changed',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($dispatcher)->post("/field/visits/{$visit->id}/submit", ['submission_token' => $token])->assertRedirect();

        $metadata = AuditEvent::query()->where('event_type', 'closeout.submitted')->firstOrFail()->metadata;
        $this->assertTrue($metadata['execute_any_override']);
        $this->assertSame($dispatcher->id, AuditEvent::query()->where('event_type', 'closeout.submitted')->firstOrFail()->actor_id);
        $this->assertArrayNotHasKey('capability_override', $metadata);
    }

    public function test_super_admin_can_execute_any_visit_without_an_assignment(): void
    {
        [$organization, $visit] = $this->executionGraph('assigned');
        [$superAdmin] = $this->userWithRole('super_admin', $organization);

        $this->actingAs($superAdmin)->get("/field/visits/{$visit->id}")
            ->assertOk()
            ->assertSee('Start En Route');
        $this->actingAs($superAdmin)->post("/field/visits/{$visit->id}/transition", ['status' => 'en_route'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('en_route', $visit->fresh()->status);
        $this->assertDatabaseHas('visit_time_entries', [
            'visit_id' => $visit->id,
            'user_id' => $superAdmin->id,
            'category' => 'travel',
            'active_user_id' => $superAdmin->id,
        ]);
    }

    public function test_inactive_and_cross_organization_memberships_cannot_write(): void
    {
        [$organization, $visit, $lead] = $this->executionGraph('on_site');
        $lead->memberships()->where('organization_id', $organization->id)->update(['status' => 'inactive']);
        $this->actingAs($lead)->post("/field/visits/{$visit->id}/draft", $this->resolvedDraft())->assertForbidden();

        [$outsider, $otherOrganization] = $this->userWithRole('technician');
        $this->actingAs($outsider)->post("/field/visits/{$visit->id}/draft", $this->resolvedDraft())->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['organization_id' => $otherOrganization->id, 'event_type' => 'security.cross_organization_record_denied']);
    }

    public function test_technicians_can_correct_only_their_own_ended_time_with_a_safe_audit(): void
    {
        [, $visit, $lead, $crew] = $this->executionGraph('on_site');
        $this->actingAs($lead)->post("/field/visits/{$visit->id}/timer", ['action' => 'start', 'category' => 'on_site'])->assertRedirect();
        $this->actingAs($lead)->post("/field/visits/{$visit->id}/timer", ['action' => 'stop', 'category' => 'on_site'])->assertRedirect();
        $entry = VisitTimeEntry::query()->where('user_id', $lead->id)->firstOrFail();
        $payload = ['started_at' => '2026-07-30T09:00', 'ended_at' => '2026-07-30T10:00', 'correction_reason' => 'Private correction explanation'];

        $this->actingAs($crew)->put("/field/visits/{$visit->id}/time/{$entry->id}", $payload)->assertNotFound();
        $this->actingAs($lead)->put("/field/visits/{$visit->id}/time/{$entry->id}", $payload)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('2026-07-30 14:00:00', $entry->fresh()->started_at->utc()->format('Y-m-d H:i:s'));
        $event = AuditEvent::query()->where('event_type', 'visit_time.corrected')->firstOrFail();
        $this->assertSame(['started_at', 'ended_at'], $event->metadata['changed_fields']);
        $this->assertStringNotContainsString('Private correction explanation', json_encode($event->metadata));
    }

    public function test_opt_in_workspace_v2_preserves_classic_default_policy_and_shared_visit(): void
    {
        [$organization, $visit, $lead] = $this->executionGraph('on_site');

        $this->actingAs($lead)->get(route('field.visits.show', $visit))
            ->assertOk()
            ->assertSee('Try new Visit workspace')
            ->assertSee(route('field.visits.workspace-v2', $visit), false);

        $this->actingAs($lead)->get(route('field.visits.workspace-v2', $visit))
            ->assertOk()
            ->assertSee('Switch to classic workspace')
            ->assertSee(route('field.visits.show', $visit), false)
            ->assertSee('data-field-workspace-v2', false)
            ->assertSee('data-v2-tab="overview"', false)
            ->assertSee('data-v2-tab="closeout"', false)
            ->assertSee('data-v2-photo-camera', false)
            ->assertSee('data-v2-photo-gallery', false)
            ->assertSee('multiple', false);

        [$outsider] = $this->userWithRole('technician');
        $this->actingAs($outsider)->get(route('field.visits.workspace-v2', $visit))->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['organization_id' => $outsider->memberships()->firstOrFail()->organization_id, 'event_type' => 'security.cross_organization_record_denied']);
        $this->assertFalse(Schema::hasTable('field_visit_workspaces'));
        $this->assertSame($organization->id, $visit->organization_id);
    }

    public function test_workspace_v2_saved_acknowledgment_fallback_cannot_be_blocked_by_hidden_signature_controls(): void
    {
        [, $visit, $lead] = $this->executionGraph('on_site');

        $this->actingAs($lead)->postJson(route('field.visits.draft', $visit), $this->resolvedDraft())
            ->assertOk();

        $response = $this->actingAs($lead)->get(route('field.visits.workspace-v2', $visit->fresh()))
            ->assertOk()
            ->assertSee('Acknowledgment fallback selected');

        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*data-v2-acknowledgment-confirmation)(?=[^>]*\bdisabled\b)(?![^>]*\brequired\b)[^>]*>/',
            $response->getContent(),
        );

        $this->actingAs($lead)->post(route('field.visits.submit', $visit), [
            'submission_token' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('submitted', $visit->fresh()->currentCloseout->status);
        $this->assertSame('pending_closeout', $visit->fresh()->status);
    }

    public function test_workspace_v2_json_draft_and_photo_are_safe_and_visible_in_classic(): void
    {
        Storage::fake('local');
        [, $visit, $lead] = $this->executionGraph('on_site');

        $draft = $this->actingAs($lead)->postJson(route('field.visits.draft', $visit), $this->resolvedDraft());
        $draft->assertOk()->assertJsonPath('message', 'Draft saved.')->assertJsonPath('content_version', 2);

        $photo = UploadedFile::fake()->image('workspace.jpg');
        $response = $this->actingAs($lead)->post(route('field.visits.media.store', $visit), [
            'photo' => $photo,
            'category' => 'after',
            'caption' => 'Workspace evidence',
        ], ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonStructure(['id', 'category', 'caption', 'show_url', 'remove_url', 'readiness_errors']);
        $this->assertArrayNotHasKey('storage_key', $response->json());
        $this->assertArrayNotHasKey('storage_disk', $response->json());
        $this->assertArrayNotHasKey('path', $response->json());

        $this->actingAs($lead)->get(route('field.visits.show', $visit->fresh()))
            ->assertOk()->assertSee(route('field.media.show', $response->json('id')), false);
        $this->actingAs($lead)->get(route('field.visits.workspace-v2', $visit->fresh()))
            ->assertOk()->assertSee('Workspace evidence');
    }

    public function test_workspace_v2_and_classic_share_work_time_parts_and_conflict_semantics(): void
    {
        [, $visit, $lead] = $this->executionGraph('on_site');
        $this->actingAs($lead)->postJson(route('field.visits.draft', $visit), [
            'content_version' => 1,
            'outcome' => 'resolved',
            'diagnosis' => 'Initial diagnosis',
        ])->assertOk()->assertJsonPath('content_version', 2);

        $this->actingAs($lead)->postJson(route('field.visits.draft', $visit), [
            'content_version' => 1,
            'outcome' => 'resolved',
            'diagnosis' => 'Stale overwrite',
        ])->assertConflict()->assertJsonPath('content_version', 2);
        $this->assertSame('Initial diagnosis', $visit->fresh()->currentCloseout->diagnosis);

        $this->actingAs($lead)->from(route('field.visits.workspace-v2', $visit))->post(route('field.visits.work-items.store', $visit), [
            'title' => 'Replace damaged patch lead',
            'detail' => 'Discovered at the rack',
            'work_note' => 'Replacement installed',
        ])->assertRedirect(route('field.visits.workspace-v2', $visit));
        $this->actingAs($lead)->post(route('field.visits.parts.store', $visit), [
            'description' => 'Patch lead', 'quantity' => 1, 'unit' => 'each', 'billing_treatment' => 'billable',
        ])->assertRedirect();
        $this->actingAs($lead)->post(route('field.visits.timer', $visit), ['action' => 'start', 'category' => 'on_site'])->assertRedirect();

        foreach (['field.visits.show', 'field.visits.workspace-v2'] as $route) {
            $this->actingAs($lead)->get(route($route, $visit->fresh()))
                ->assertOk()
                ->assertSee('Replace damaged patch lead')
                ->assertSee('Patch lead')
                ->assertSee('running', false);
        }
    }

    public function test_workspace_v2_read_only_states_hide_mutations_and_json_remove_keeps_classic_compatibility(): void
    {
        Storage::fake('local');
        Queue::fake();
        [, $visit, $lead] = $this->executionGraph('on_site');
        $this->actingAs($lead)->postJson(route('field.visits.draft', $visit), $this->resolvedDraft())->assertOk();
        $upload = $this->actingAs($lead)->post(route('field.visits.media.store', $visit), [
            'photo' => UploadedFile::fake()->image('remove.jpg'), 'category' => 'after',
        ], ['Accept' => 'application/json'])->assertCreated();
        $mediaId = $upload->json('id');

        $this->actingAs($lead)->deleteJson(route('field.visits.media.remove', [$visit, $mediaId]))
            ->assertOk()->assertJsonPath('id', $mediaId)->assertJsonStructure(['readiness_errors']);
        $this->assertDatabaseHas('visit_media', ['id' => $mediaId, 'state' => 'removed']);

        $visit->update(['status' => 'canceled']);
        $this->actingAs($lead)->get(route('field.visits.workspace-v2', $visit))
            ->assertOk()->assertSee('Canceled Visit · read-only')->assertDontSee('data-v2-upload-form', false)->assertDontSee('data-v2-finish-dialog', false);
    }

    public function test_workspace_v2_query_load_stays_close_to_classic(): void
    {
        [, $visit, $lead] = $this->executionGraph('on_site');
        $this->actingAs($lead)->postJson(route('field.visits.draft', $visit), $this->resolvedDraft())->assertOk();

        $measure = function (string $route) use ($visit): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $started = hrtime(true);
            $this->get(route($route, $visit))->assertOk();
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$queries, $elapsed];
        };

        [$v1Queries] = $measure('field.visits.show');
        [$v2Queries] = $measure('field.visits.workspace-v2');
        $v1Times = [];
        $v2Times = [];
        for ($run = 0; $run < 10; $run++) {
            [, $v1Times[]] = $measure('field.visits.show');
            [, $v2Times[]] = $measure('field.visits.workspace-v2');
        }
        sort($v1Times);
        sort($v2Times);
        $v1P95 = $v1Times[9];
        $v2P95 = $v2Times[9];

        $this->assertLessThanOrEqual($v1Queries + 5, $v2Queries, "V1 {$v1Queries} queries; V2 {$v2Queries} queries.");
        if (env('WORKSPACE_BENCHMARK_REPORT')) {
            fwrite(STDOUT, sprintf("\nWorkspace benchmark: V1 %d queries / %.1f ms p95; V2 %d queries / %.1f ms p95\n", $v1Queries, $v1P95, $v2Queries, $v2P95));
        }
    }

    /** @return array{Organization, Visit, User, User} */
    private function executionGraph(string $status = 'on_site'): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$lead, , $leadMembership] = $this->userWithRole('technician', $organization);
        [$crew, , $crewMembership] = $this->userWithRole('technician', $organization);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $contact = Contact::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'name' => 'Field Contact', 'is_preferred' => true, 'active' => true]);
        $location = ServiceLocation::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'primary_contact_id' => $contact->id, 'name' => 'Field Site', 'address_line_1' => '100 Main', 'city' => 'Fort Worth', 'state' => 'TX', 'postal_code' => '76102', 'timezone' => 'America/Chicago', 'is_primary' => true, 'active' => true]);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'contact_id' => $contact->id, 'ticket_number' => 'NDT-ST-2026-0001', 'title' => 'Field execution', 'description' => 'Restore service', 'priority' => 'normal', 'source' => 'phone', 'status' => 'open']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'status' => $status, 'timezone' => 'America/Chicago']);
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $leadMembership->id, 'is_lead' => true]);
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $crewMembership->id, 'is_lead' => false]);

        return [$organization, $visit, $lead, $crew];
    }

    private function additionalAssignedVisit(Visit $source, User $lead, string $status): Visit
    {
        $membership = $lead->memberships()->where('organization_id', $source->organization_id)->firstOrFail();
        $visit = Visit::query()->create(['organization_id' => $source->organization_id, 'service_ticket_id' => $source->service_ticket_id, 'service_location_id' => $source->service_location_id, 'status' => $status, 'timezone' => $source->timezone]);
        VisitAssignment::query()->create(['organization_id' => $source->organization_id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);

        return $visit;
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

    private function resolvedDraft(int $version = 1): array
    {
        return ['content_version' => $version, 'outcome' => 'resolved', 'diagnosis' => 'Sensitive diagnosis', 'work_performed' => 'Restored service', 'ack_unavailable_category' => 'remote_service', 'ack_unavailable_detail' => 'Confirmed remotely', 'no_photo_category' => 'not_applicable', 'no_photo_detail' => 'No visual change'];
    }

    private function returnDraft(): array
    {
        return ['content_version' => 1, 'outcome' => 'needs_return_trip', 'diagnosis' => 'Failed component', 'work_performed' => 'Isolated fault', 'return_reason' => 'Replacement required', 'unfinished_work' => 'Install replacement', 'needed_equipment' => 'Replacement switch', 'recommendations' => 'Schedule return', 'ack_unavailable_category' => 'remote_service', 'ack_unavailable_detail' => 'Reviewed remotely'];
    }

    private function holdDraft(): array
    {
        return ['content_version' => 1, 'outcome' => 'on_hold', 'hold_reason' => 'Awaiting site access', 'recommendations' => 'Coordinate access', 'ack_unavailable_category' => 'representative_unavailable', 'ack_unavailable_detail' => 'No representative available'];
    }

    private function signaturePng(bool $withInk = true): string
    {
        $width = 300;
        $height = 120;
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0";
            for ($x = 0; $x < $width; $x++) {
                $ink = $withInk && $x >= 40 && $x <= 260 && abs($y - (35 + intdiv($x, 6) % 45)) < 3;
                $raw .= $ink ? "\x0f\x17\x2a\xff" : "\xff\xff\xff\0";
            }
        }
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)).$type.$data.pack('H*', hash('crc32b', $type.$data));
        };
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            .$chunk('IDAT', gzcompress($raw, 9))
            .$chunk('IEND', '');

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
