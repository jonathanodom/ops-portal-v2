<?php

namespace Tests\Feature;

use App\Domain\Commercial\ChangeOrderApplicationWorkflow;
use App\Domain\Commercial\ChangeOrderWorkflow;
use App\Domain\Commercial\CommercialApprovalWorkflow;
use App\Domain\Commercial\CommercialDefaults;
use App\Domain\Commercial\CommercialRevisionMediaWorkflow;
use App\Domain\Commercial\ProjectAllowanceWorkflow;
use App\Domain\Commercial\ProjectConversionWorkflow;
use App\Domain\Commercial\ProposalPublicationWorkflow;
use App\Domain\Commercial\QuoteWorkflow;
use App\Domain\Projects\Actions\ProjectWorkflow;
use App\Jobs\DeleteRemovedCommercialRevisionMedia;
use App\Jobs\DeliverProposalPublication;
use App\Jobs\QueueProposalPublicationReminders;
use App\Jobs\RenderProposalPublicationPdf;
use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CommercialContentBlock;
use App\Models\CommercialTermsSet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\ProjectConversionTemplate;
use App\Models\ProposalAcceptance;
use App\Models\ProposalDeliveryAttempt;
use App\Models\ProposalEngagementEvent;
use App\Models\ProposalTemplate;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\IncidentRecorder;
use Closure;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialOperationsPhase4Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_exact_policy_boundaries_pass_and_publication_freezes_customer_safe_snapshot(): void
    {
        Queue::fake();
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(6800, 10000);
        $line = $revision->lines()->sole();
        app(QuoteWorkflow::class)->updateLine($revision, $line, $admin, ['content_version' => $revision->content_version, 'description' => $line->description, 'quantity_millis' => 1000, 'pricing_mode' => 'catalog', 'effective_unit_sell_cents' => 10000, 'pricing_value_basis_points' => 0, 'discount_type' => 'percent', 'discount_value' => 1500, 'optional' => false, 'included' => true, 'taxable' => false]);
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        $revision = $revision->fresh();
        $approval = app(CommercialApprovalWorkflow::class)->submit($revision, $admin);
        $this->assertSame('policy_pass', $approval->status);
        $this->assertSame('approved', $revision->fresh()->status);
        $template = ProposalTemplate::query()->forOrganization($organization->id)->where('template_type', 'budgetary_estimate')->sole();
        $publication = app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, ['expires_at' => now()->addDays(30), 'acceptance_enabled' => false, 'labor_grouping' => 'location']);
        $this->assertSame(8500, $publication->total_cents);
        $this->assertArrayNotHasKey('resolved_cost_cents', $publication->snapshot['lines'][0]);
        $this->assertArrayNotHasKey('gross_margin_basis_points', $publication->snapshot);
        $this->assertSame('location', $publication->snapshot['publication']['labor_grouping']);
        $this->assertFalse($publication->snapshot['publication']['acceptance_enabled']);
        $this->assertSame($publication->publication_hash, hash('sha256', json_encode($publication->snapshot, JSON_THROW_ON_ERROR)));
        $this->assertSame('published', $revision->fresh()->status);
        CatalogProduct::query()->where('organization_id', $organization->id)->update(['name' => 'Changed after publication', 'default_sell_price_cents' => 99999]);
        $retry = app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, ['expires_at' => now()->addDays(45), 'acceptance_enabled' => true, 'labor_grouping' => 'system']);
        $this->assertSame($publication->id, $retry->id);
        $this->assertSame('Proposal item', $retry->snapshot['lines'][0]['description']);
        Queue::assertPushed(RenderProposalPublicationPdf::class, fn ($job) => $job->publicationId === $publication->id);
    }

    public function test_commercial_read_surfaces_stay_within_bounded_query_budgets(): void
    {
        Queue::fake();
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(8000, 10000);

        $this->assertQueryBudget(65, fn () => $this->actingAs($admin)->get(route('office.opportunities.index')));
        $this->assertQueryBudget(80, fn () => $this->actingAs($admin)->get(route('office.opportunities.show', $opportunity)));
        $this->assertQueryBudget(85, fn () => $this->actingAs($admin)->get(route('office.quotes.show', [$revision->document, $revision])));

        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        app(CommercialApprovalWorkflow::class)->submit($revision->fresh(), $admin);
        $template = ProposalTemplate::query()->forOrganization($organization->id)->where('template_type', 'budgetary_estimate')->sole();
        $publication = app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, ['expires_at' => now()->addDays(30), 'acceptance_enabled' => false, 'labor_grouping' => 'location']);
        [, $token] = app(ProposalPublicationWorkflow::class)->addShareLink($publication, $admin, 'Phase 9 performance link');

        $this->assertQueryBudget(75, fn () => $this->actingAs($admin)->get(route('office.proposal-publications.show', $publication)));
        $this->assertQueryBudget(50, fn () => $this->get(route('proposals.show', $token)));
    }

    public function test_all_approval_triggers_are_captured_together_without_sensitive_content(): void
    {
        [$organization, $admin, $opportunity, $revision] = $this->context(9000, 10000);
        $line = $revision->lines()->sole();
        app(QuoteWorkflow::class)->updateLine($revision, $line, $admin, ['content_version' => $revision->content_version, 'description' => $line->description, 'quantity_millis' => 1000, 'pricing_mode' => 'direct', 'effective_unit_sell_cents' => 11000, 'pricing_value_basis_points' => 0, 'discount_type' => 'percent', 'discount_value' => 2000, 'optional' => false, 'included' => true, 'taxable' => false]);
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->updateTerms($revision, null, $admin, $revision->content_version, 'Customer-specific commercial terms.');

        $approval = app(CommercialApprovalWorkflow::class)->submit($revision->fresh(), $admin);

        $this->assertSame('pending', $approval->status);
        $this->assertEqualsCanonicalizing([
            'gross_margin_below_floor',
            'effective_discount_above_ceiling',
            'below_cost_lines',
            'manual_sell_price_override',
            'terms_override',
        ], collect($approval->trigger_snapshot)->pluck('kind')->all());
        $this->assertStringNotContainsString('Customer-specific', json_encode($approval->trigger_snapshot, JSON_THROW_ON_ERROR));
    }

    public function test_acceptance_publication_with_payment_schedule_requires_active_service_location(): void
    {
        Queue::fake();
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(8000, 10000);
        app(QuoteWorkflow::class)->addMilestone($revision, $admin, ['content_version' => $revision->content_version, 'name' => 'Deposit', 'amount_type' => 'percent', 'amount_value' => 5000, 'is_balancing' => false]);
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        $revision = $revision->fresh();
        app(CommercialApprovalWorkflow::class)->submit($revision, $admin);
        $template = ProposalTemplate::query()->forOrganization($organization->id)->where('template_type', 'quick_quote')->sole();

        $this->expectException(ValidationException::class);
        app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, ['expires_at' => now()->addDays(30), 'acceptance_enabled' => true, 'labor_grouping' => 'location']);
    }

    public function test_manual_price_override_requires_hash_bound_approval_and_stale_decision_is_rejected(): void
    {
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(5000, 10000);
        $line = $revision->lines()->sole();
        app(QuoteWorkflow::class)->updateLine($revision, $line, $admin, ['content_version' => $revision->content_version, 'description' => $line->description, 'quantity_millis' => 1000, 'pricing_mode' => 'direct', 'effective_unit_sell_cents' => 11000, 'pricing_value_basis_points' => 0, 'discount_type' => null, 'discount_value' => null, 'optional' => false, 'included' => true, 'taxable' => false]);
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        $revision = $revision->fresh();
        $approval = app(CommercialApprovalWorkflow::class)->submit($revision, $admin);
        $this->assertSame('pending', $approval->status);
        $this->assertContains('manual_sell_price_override', collect($approval->trigger_snapshot)->pluck('kind'));
        app(CommercialApprovalWorkflow::class)->decide($approval, $admin, 'rejected', 'Use standard price.');
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->updateLine($revision, $line->fresh(), $admin, ['content_version' => $revision->content_version, 'description' => $line->description, 'quantity_millis' => 1000, 'pricing_mode' => 'catalog', 'effective_unit_sell_cents' => 10000, 'pricing_value_basis_points' => 0, 'discount_type' => null, 'discount_value' => null, 'optional' => false, 'included' => true, 'taxable' => false]);
        $this->expectException(ValidationException::class);
        app(CommercialApprovalWorkflow::class)->decide($approval->fresh(), $admin, 'approved', 'Stale decision.');
    }

    public function test_tokens_are_hash_only_and_public_customer_route_is_secure_in_phase_five(): void
    {
        Queue::fake();
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(8000, 10000);
        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        $revision = $revision->fresh();
        app(CommercialApprovalWorkflow::class)->submit($revision, $admin);
        $template = ProposalTemplate::query()->forOrganization($organization->id)->where('template_type', 'budgetary_estimate')->sole();
        $publication = app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, ['expires_at' => now()->addDays(30), 'acceptance_enabled' => false, 'labor_grouping' => 'location']);
        [$recipient, $token] = app(ProposalPublicationWorkflow::class)->addRecipient($publication, $admin, 'customer@example.test', 'Customer');
        [$link, $shareToken] = app(ProposalPublicationWorkflow::class)->addShareLink($publication, $admin, 'Local review');
        $this->assertNotSame($token, $recipient->token_hash);
        $this->assertSame(hash('sha256', $token), $recipient->token_hash);
        $this->assertSame(hash('sha256', $shareToken), $link->token_hash);
        $this->get(route('proposals.show', $token), ['User-Agent' => 'Phase 5 browser'])
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('Scope and pricing');
        $event = ProposalEngagementEvent::query()->sole();
        $this->assertSame('first_view', $event->event_type);
        $this->assertSame(hash('sha256', '127.0.0.1'), $event->ip_hash);
        $this->assertSame('127.0.0.1', $event->encrypted_ip);
        $this->assertStringNotContainsString('127.0.0.1', $event->getRawOriginal('encrypted_ip'));
        $recipient->update(['revoked_at' => now()]);
        $this->get(route('proposals.show', $token))->assertNotFound();
        $this->get(route('proposals.show', $shareToken))->assertOk();
    }

    public function test_recipient_acceptance_is_idempotent_freezes_totals_and_wins_opportunity_without_billing(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(8000, 10000);
        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        $revision = $revision->fresh();
        app(CommercialApprovalWorkflow::class)->submit($revision, $admin);
        $template = ProposalTemplate::query()->forOrganization($organization->id)->where('template_type', 'budgetary_estimate')->sole();
        $publication = app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, ['expires_at' => now()->addDays(30), 'acceptance_enabled' => true, 'labor_grouping' => 'location']);
        [$recipient, $token] = app(ProposalPublicationWorkflow::class)->addRecipient($publication, $admin, 'customer@example.test', 'Customer');
        $idempotency = (string) Str::uuid();
        $payload = ['signer_name' => 'Customer Person', 'signer_email' => 'customer@example.test', 'signer_title' => 'Owner', 'consent' => '1', 'signature_data' => $this->signaturePng(), 'idempotency_token' => $idempotency];

        $this->post(route('proposals.accept', $token), $payload)->assertOk()->assertSee('Acceptance recorded');
        $this->post(route('proposals.accept', $token), $payload)->assertOk();

        $acceptance = ProposalAcceptance::query()->sole();
        $this->assertSame(10000, $acceptance->total_cents);
        $this->assertSame($publication->publication_hash, $acceptance->publication_hash);
        $this->assertSame('accepted', $publication->fresh()->status);
        $this->assertSame('won', $opportunity->fresh()->stage->semantic_kind);
        $this->assertDatabaseCount('proposal_acceptances', 1);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        Storage::disk('local')->assertExists($acceptance->signature_key);
    }

    public function test_acceptance_creates_one_tax_inclusive_draft_deposit_invoice_for_the_first_milestone(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(8000, 10000);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $opportunity->customer_id, 'active' => true]);
        $opportunity->update(['service_location_id' => $location->id]);
        app(QuoteWorkflow::class)->addMilestone($revision, $admin, ['content_version' => $revision->content_version, 'name' => 'Deposit', 'amount_type' => 'percent', 'amount_value' => 3000, 'is_balancing' => false]);
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->addMilestone($revision, $admin, ['content_version' => $revision->content_version, 'name' => 'Balance', 'amount_type' => 'percent', 'amount_value' => 7000, 'is_balancing' => true]);
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        app(CommercialApprovalWorkflow::class)->submit($revision->fresh(), $admin);
        $template = ProposalTemplate::query()->forOrganization($organization->id)->where('template_type', 'budgetary_estimate')->sole();
        $publication = app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, ['expires_at' => now()->addDays(30), 'acceptance_enabled' => true, 'labor_grouping' => 'location']);
        [, $token] = app(ProposalPublicationWorkflow::class)->addRecipient($publication, $admin, 'customer@example.test', 'Customer');
        $payload = ['signer_name' => 'Customer Person', 'signer_email' => 'customer@example.test', 'signer_title' => 'Owner', 'consent' => '1', 'signature_data' => $this->signaturePng(), 'idempotency_token' => (string) Str::uuid()];

        $this->post(route('proposals.accept', $token), $payload)->assertOk();
        $this->post(route('proposals.accept', $token), $payload)->assertOk();

        $acceptance = ProposalAcceptance::query()->with('milestones.invoice.lines')->sole();
        $deposit = $acceptance->milestones->first();
        $this->assertSame(3000, $deposit->allocated_cents);
        $this->assertNotNull($deposit->invoice_id);
        $this->assertSame(3000, $deposit->invoice->total_cents);
        $this->assertSame('draft', $deposit->invoice->status);
        $this->assertSame(0, $deposit->invoice->tax_total_cents);
        $this->assertSame(1, Invoice::query()->count());
        $this->assertNull($acceptance->milestones->last()->invoice_id);
    }

    public function test_accepted_scope_converts_once_into_project_planning_tickets_and_later_milestone_billing(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(8000, 10000);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $opportunity->customer_id, 'active' => true]);
        $opportunity->update(['service_location_id' => $location->id]);
        app(QuoteWorkflow::class)->addMilestone($revision, $admin, ['content_version' => $revision->content_version, 'name' => 'Deposit', 'amount_type' => 'percent', 'amount_value' => 5000, 'is_balancing' => false]);
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->addMilestone($revision, $admin, ['content_version' => $revision->content_version, 'name' => 'Completion', 'amount_type' => 'percent', 'amount_value' => 5000, 'is_balancing' => true]);
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        app(CommercialApprovalWorkflow::class)->submit($revision->fresh(), $admin);
        $template = ProposalTemplate::query()->forOrganization($organization->id)->where('template_type', 'budgetary_estimate')->sole();
        $publication = app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, ['expires_at' => now()->addDays(30), 'acceptance_enabled' => true, 'labor_grouping' => 'location']);
        [, $token] = app(ProposalPublicationWorkflow::class)->addRecipient($publication, $admin, 'customer@example.test', 'Customer');
        $this->post(route('proposals.accept', $token), ['signer_name' => 'Customer Person', 'signer_email' => 'customer@example.test', 'signer_title' => 'Owner', 'consent' => '1', 'signature_data' => $this->signaturePng(), 'idempotency_token' => (string) Str::uuid()])->assertOk();
        $acceptance = ProposalAcceptance::query()->with(['selections', 'milestones'])->sole();
        $conversionTemplate = ProjectConversionTemplate::query()->where('organization_id', $organization->id)->sole();
        $lineId = $acceptance->selections->sole()->publication_line_id;
        $data = ['project_mode' => 'new', 'project_name' => 'Accepted Installation', 'project_type' => 'installation_project', 'project_conversion_template_id' => $conversionTemplate->id, 'confirm_location_mismatch' => false, 'ticket_line_ids' => [$lineId]];
        [$reviewer] = $this->member($organization, 'reviewer');
        $this->actingAs($reviewer)->get(route('office.proposal-publications.convert.create', $publication))->assertForbidden();
        $this->actingAs($admin)->get(route('office.proposal-publications.convert.create', $publication))->assertOk()->assertSee('Convert accepted scope');

        $scope = app(ProjectConversionWorkflow::class)->convert($acceptance, $admin, $data);
        $retry = app(ProjectConversionWorkflow::class)->convert($acceptance, $admin, $data);

        $this->assertSame($scope->id, $retry->id);
        $this->assertCount(1, $scope->materialItems);
        $this->assertCount(0, $scope->laborItems);
        $this->assertDatabaseCount('project_commercial_scopes', 1);
        $this->assertDatabaseCount('service_tickets', 1);
        $this->assertDatabaseCount('project_service_ticket', 1);
        $this->assertDatabaseCount('project_billing_milestones', 2);
        $completion = $scope->project->milestones()->whereHas('billingMilestone.acceptedMilestone', fn ($query) => $query->where('name', 'Completion'))->sole();
        app(ProjectWorkflow::class)->updateMilestone($scope->project, $completion, $admin, ['name' => $completion->name, 'description' => $completion->description, 'status' => 'completed', 'target_on' => null, 'sort_order' => $completion->sort_order]);
        $this->assertDatabaseCount('invoices', 2);
        $this->assertSame('draft', $acceptance->milestones()->where('name', 'Completion')->sole()->invoice->status);
    }

    public function test_options_comments_and_change_request_are_scoped_and_clone_the_complete_revision(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(8000, 10000);
        $line = $revision->lines()->sole();
        app(QuoteWorkflow::class)->updateLine($revision, $line, $admin, ['content_version' => $revision->content_version, 'description' => $line->description, 'quantity_millis' => 1000, 'pricing_mode' => 'catalog', 'effective_unit_sell_cents' => 10000, 'pricing_value_basis_points' => 0, 'discount_type' => null, 'discount_value' => null, 'optional' => true, 'included' => false, 'taxable' => false]);
        $revision = $revision->fresh();
        app(CommercialRevisionMediaWorkflow::class)->upload($revision, $admin, UploadedFile::fake()->image('scope.png'), 'Customer scope');
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        $revision = $revision->fresh();
        app(CommercialApprovalWorkflow::class)->submit($revision, $admin);
        $template = ProposalTemplate::query()->forOrganization($organization->id)->where('template_type', 'budgetary_estimate')->sole();
        $publication = app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, ['expires_at' => now()->addDays(30), 'acceptance_enabled' => true, 'labor_grouping' => 'location']);
        [$link, $token] = app(ProposalPublicationWorkflow::class)->addShareLink($publication, $admin, 'Customer review');

        $this->postJson(route('proposals.options', $token), ['options' => [$line->id]])->assertOk()->assertJson(['total' => '$100.00']);
        $this->post(route('proposals.comments.store', $token), ['name' => 'Customer', 'email' => 'customer@example.test', 'target_type' => 'line', 'target_reference' => (string) $line->id, 'body' => 'Please clarify this option.'])->assertRedirect();
        $this->post(route('proposals.request-changes', $token), ['name' => 'Customer', 'email' => 'customer@example.test', 'body' => 'Please revise the scope.', 'confirm' => '1'])->assertRedirect();

        $draft = $revision->document->revisions()->where('status', 'draft')->sole();
        $this->assertSame('changes_requested', $publication->fresh()->status);
        $this->assertSame('quoting', $opportunity->fresh()->stage->semantic_kind);
        $this->assertCount(1, $draft->media);
        Storage::disk('local')->assertExists($draft->media->sole()->storage_key);
        $this->assertDatabaseHas('proposal_option_selections', ['proposal_share_link_id' => $link->id, 'publication_line_id' => $line->id, 'included' => true]);
        $this->assertStringNotContainsString('Please revise', json_encode(AuditEvent::query()->where('subject_type', $opportunity->getMorphClass())->where('subject_id', $opportunity->id)->pluck('metadata')->all(), JSON_THROW_ON_ERROR));
    }

    public function test_negative_change_order_requires_extra_approval_and_applies_once_as_signed_project_delta(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(8000, 10000);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $opportunity->customer_id, 'active' => true]);
        $opportunity->update(['service_location_id' => $location->id]);
        foreach (['Exact allowance', 'Positive variance allowance', 'Negative variance allowance'] as $allowanceName) {
            app(QuoteWorkflow::class)->addAllowance($revision, $admin, ['content_version' => $revision->content_version, 'description' => $allowanceName, 'amount_cents' => 5000, 'optional' => false, 'taxable' => false]);
            $revision = $revision->fresh();
        }
        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        app(CommercialApprovalWorkflow::class)->submit($revision->fresh(), $admin);
        $template = ProposalTemplate::query()->forOrganization($organization->id)->where('template_type', 'budgetary_estimate')->sole();
        $publication = app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, ['expires_at' => now()->addDays(30), 'acceptance_enabled' => true, 'labor_grouping' => 'location']);
        [, $token] = app(ProposalPublicationWorkflow::class)->addRecipient($publication, $admin, 'customer@example.test', 'Customer');
        $this->post(route('proposals.accept', $token), ['signer_name' => 'Customer Person', 'signer_email' => 'customer@example.test', 'signer_title' => 'Owner', 'consent' => '1', 'signature_data' => $this->signaturePng(), 'idempotency_token' => (string) Str::uuid()])->assertOk();
        $baselineAcceptance = ProposalAcceptance::query()->where('proposal_publication_id', $publication->id)->with(['selections', 'milestones'])->sole();
        $baseline = app(ProjectConversionWorkflow::class)->convert($baselineAcceptance, $admin, ['project_mode' => 'new', 'project_name' => 'Accepted Installation', 'project_type' => 'installation_project', 'project_conversion_template_id' => null, 'confirm_location_mismatch' => false, 'ticket_line_ids' => []]);
        $allowances = $baselineAcceptance->selections->filter(fn ($selection) => ($selection->line_snapshot['type'] ?? null) === 'allowance')->values();
        $exact = app(ProjectAllowanceWorkflow::class)->resolve($baseline->project, $baseline, $allowances[0]->publication_line_id, 5000, $admin);
        $positiveVariance = app(ProjectAllowanceWorkflow::class)->resolve($baseline->project, $baseline, $allowances[1]->publication_line_id, 6000, $admin);
        $negativeVariance = app(ProjectAllowanceWorkflow::class)->resolve($baseline->project, $baseline, $allowances[2]->publication_line_id, 4000, $admin);
        $this->assertSame('resolved_within_allowance', $exact->status);
        $this->assertSame(1000, $positiveVariance->variance_cents);
        $this->assertSame(-1000, $negativeVariance->variance_cents);
        $this->assertSame('change_order_required', $positiveVariance->status);
        $this->assertSame(25000, $baseline->project->currentContractTotalCents());

        $product = CatalogProduct::query()->where('organization_id', $organization->id)->sole();
        $zeroOrder = app(ChangeOrderWorkflow::class)->create($baseline->project, $admin, 'Substitute equipment');
        $zeroRevision = $zeroOrder->revisions()->sole();
        app(QuoteWorkflow::class)->addCatalogLine($zeroRevision, $admin, ['content_version' => $zeroRevision->content_version, 'catalog_item_type' => 'product', 'catalog_item_id' => $product->id, 'quantity_millis' => 1000]);
        $zeroRevision = $zeroRevision->fresh();
        app(QuoteWorkflow::class)->addCatalogLine($zeroRevision, $admin, ['content_version' => $zeroRevision->content_version, 'catalog_item_type' => 'product', 'catalog_item_id' => $product->id, 'quantity_millis' => 1000]);
        $zeroRevision = $zeroRevision->fresh();
        $zeroLines = $zeroRevision->lines()->orderBy('id')->get();
        app(QuoteWorkflow::class)->updateChangeEffects($zeroRevision, $admin, $zeroRevision->content_version, [$zeroLines[0]->id => 'substitute_remove', $zeroLines[1]->id => 'substitute_add'], [$zeroLines[0]->id => 'equipment', $zeroLines[1]->id => 'equipment']);
        $this->assertSame(0, $zeroRevision->fresh()->change_order_delta_cents);
        $this->assertSame(25000, $zeroRevision->fresh()->resulting_project_total_cents);

        $positiveOrder = app(ChangeOrderWorkflow::class)->create($baseline->project, $admin, 'Add equipment');
        $positiveRevision = $positiveOrder->revisions()->sole();
        app(QuoteWorkflow::class)->addCatalogLine($positiveRevision, $admin, ['content_version' => $positiveRevision->content_version, 'catalog_item_type' => 'product', 'catalog_item_id' => $product->id, 'quantity_millis' => 1000]);
        $this->assertSame(10000, $positiveRevision->fresh()->change_order_delta_cents);
        $this->assertSame(35000, $positiveRevision->fresh()->resulting_project_total_cents);

        $changeOrder = app(ChangeOrderWorkflow::class)->create($baseline->project, $admin, 'Remove unused equipment');
        $changeRevision = $changeOrder->revisions()->sole();
        $this->assertSame('CO-2026-0003', $changeOrder->document_number);
        app(QuoteWorkflow::class)->addCatalogLine($changeRevision, $admin, ['content_version' => $changeRevision->content_version, 'catalog_item_type' => 'product', 'catalog_item_id' => $product->id, 'quantity_millis' => 1000]);
        $changeRevision = $changeRevision->fresh();
        $changeLine = $changeRevision->lines()->sole();
        app(QuoteWorkflow::class)->updateChangeEffects($changeRevision, $admin, $changeRevision->content_version, [$changeLine->id => 'remove'], []);
        $changeRevision = $changeRevision->fresh();
        $this->assertSame(-10000, $changeRevision->change_order_delta_cents);
        $this->assertSame(15000, $changeRevision->resulting_project_total_cents);
        app(QuoteWorkflow::class)->updateTerms($changeRevision, $terms, $admin, $changeRevision->content_version, null);
        $approval = app(CommercialApprovalWorkflow::class)->submit($changeRevision->fresh(), $admin);
        $this->assertEqualsCanonicalizing(['change_order_manager_review', 'negative_change_order'], collect($approval->trigger_snapshot)->pluck('kind')->all());
        [$dispatcher, $dispatcherMembership] = $this->member($organization, 'dispatcher');
        $dispatcherMembership->capabilityOverrides()->attach(Capability::query()->where('key', 'quotes.approve')->sole(), ['effect' => 'grant']);
        $this->actingAs($dispatcher)->post(route('office.quote-approvals.decide', $approval), ['decision' => 'approved', 'reason' => 'Not authorized for credits.'])->assertForbidden();
        app(CommercialApprovalWorkflow::class)->decide($approval, $admin, 'approved', 'Approved customer credit.');
        $changePublication = app(ProposalPublicationWorkflow::class)->publish($changeRevision->fresh(), $template, $admin, ['expires_at' => now()->addDays(30), 'acceptance_enabled' => true, 'labor_grouping' => 'location']);
        [, $changeToken] = app(ProposalPublicationWorkflow::class)->addRecipient($changePublication, $admin, 'customer@example.test', 'Customer');
        $invoiceCount = Invoice::query()->count();
        $this->post(route('proposals.accept', $changeToken), ['signer_name' => 'Customer Person', 'signer_email' => 'customer@example.test', 'signer_title' => 'Owner', 'consent' => '1', 'signature_data' => $this->signaturePng(), 'idempotency_token' => (string) Str::uuid()])->assertOk()->assertSee('Acceptance recorded');
        $changeAcceptance = ProposalAcceptance::query()->where('proposal_publication_id', $changePublication->id)->sole();
        $this->assertSame(-10000, $changeAcceptance->change_order_delta_cents);
        $this->assertSame($invoiceCount, Invoice::query()->count());

        $scope = app(ChangeOrderApplicationWorkflow::class)->apply($changeAcceptance, $admin);
        $retry = app(ChangeOrderApplicationWorkflow::class)->apply($changeAcceptance, $admin);
        $this->assertSame($scope->id, $retry->id);
        $this->assertSame('change_order', $scope->scope_type);
        $this->assertSame(-10000, $scope->contract_delta_cents);
        $this->assertSame(15000, $scope->resulting_contract_total_cents);
        $this->assertSame(-1000, $scope->materialItems()->sole()->delta_quantity_millis);
        $this->assertSame(15000, $baseline->project->currentContractTotalCents());
        $this->assertDatabaseCount('project_commercial_scopes', 2);
        $this->assertQueryBudget(85, fn () => $this->actingAs($admin)->get(route('office.quotes.show', [$changeOrder, $changeRevision])));
        $this->assertQueryBudget(95, fn () => $this->actingAs($admin)->get(route('office.projects.show', $baseline->project)));
    }

    public function test_reminder_job_is_organization_local_and_idempotent(): void
    {
        Carbon::setTestNow('2026-08-28 14:00:00');
        Queue::fake();
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(8000, 10000);
        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        $revision = $revision->fresh();
        app(CommercialApprovalWorkflow::class)->submit($revision, $admin);
        $template = ProposalTemplate::query()->forOrganization($organization->id)->where('template_type', 'budgetary_estimate')->sole();
        $publication = app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, [
            'expires_at' => Carbon::now($organization->timezone)->addDays(7)->endOfDay()->utc(),
            'acceptance_enabled' => false,
            'labor_grouping' => 'location',
        ]);
        app(ProposalPublicationWorkflow::class)->addRecipient($publication, $admin, 'customer@example.test', 'Customer');

        app(QueueProposalPublicationReminders::class)->handle();
        app(QueueProposalPublicationReminders::class)->handle();

        $this->assertSame(1, ProposalDeliveryAttempt::query()->where('delivery_type', 'reminder')->count());
        Queue::assertPushed(DeliverProposalPublication::class, 1);
        Carbon::setTestNow();
    }

    public function test_private_media_is_opaque_scoped_hash_bound_and_cleaned_after_removal(): void
    {
        Queue::fake();
        [$organization, $admin, $opportunity, $revision] = $this->context(8000, 10000);
        $originalHash = $revision->content_hash;
        $media = app(CommercialRevisionMediaWorkflow::class)->upload($revision, $admin, UploadedFile::fake()->image('customer-diagram.png'), 'System diagram');

        Storage::disk('local')->assertExists($media->storage_key);
        $this->assertStringNotContainsString('customer-diagram', $media->storage_key);
        $this->assertSame(64, strlen($media->sha256));
        $this->assertNotSame($originalHash, $revision->fresh()->content_hash);
        $response = $this->actingAs($admin)->get(route('office.quotes.media.show', [$revision->document, $revision, $media]))->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $other = Organization::factory()->create();
        [$otherAdmin] = $this->member($other, 'super_admin');
        app(CommercialDefaults::class)->ensure($other);
        $this->actingAs($otherAdmin)->get(route('office.quotes.media.show', [$revision->document, $revision, $media]))->assertNotFound();

        app(CommercialRevisionMediaWorkflow::class)->remove($revision->fresh(), $media, $admin);
        Queue::assertPushed(DeleteRemovedCommercialRevisionMedia::class, fn ($job) => $job->mediaId === $media->id);
        (new DeleteRemovedCommercialRevisionMedia($media->id))->handle();
        Storage::disk('local')->assertMissing($media->storage_key);
    }

    public function test_pdf_job_is_private_idempotent_and_reads_the_frozen_snapshot(): void
    {
        Queue::fake();
        [$organization, $admin, $opportunity, $revision, $terms] = $this->context(8000, 10000);
        app(QuoteWorkflow::class)->updateTerms($revision, $terms, $admin, $revision->content_version, null);
        $revision = $revision->fresh();
        app(CommercialApprovalWorkflow::class)->submit($revision, $admin);
        $template = ProposalTemplate::query()->forOrganization($organization->id)->where('template_type', 'budgetary_estimate')->sole();
        $publication = app(ProposalPublicationWorkflow::class)->publish($revision->fresh(), $template, $admin, ['expires_at' => now()->addDays(30), 'acceptance_enabled' => false, 'labor_grouping' => 'location']);

        (new RenderProposalPublicationPdf($publication->id))->handle(app(IncidentRecorder::class));
        $publication->refresh();
        $firstKey = $publication->pdf_key;
        $this->assertSame('ready', $publication->pdf_status);
        Storage::disk($publication->pdf_disk)->assertExists($firstKey);
        $this->assertStringStartsWith('%PDF', Storage::disk($publication->pdf_disk)->get($firstKey));

        CatalogProduct::query()->where('organization_id', $organization->id)->update(['name' => 'Mutable Catalog change']);
        (new RenderProposalPublicationPdf($publication->id))->handle(app(IncidentRecorder::class));
        $this->assertSame($firstKey, $publication->fresh()->pdf_key);
    }

    public function test_publication_and_library_routes_enforce_capabilities_and_organization_scope(): void
    {
        [$organization, $admin, $opportunity, $revision] = $this->context(8000, 10000);
        [$dispatcher, $dispatcherMembership] = $this->member($organization, 'dispatcher');
        $this->actingAs($dispatcher)->get(route('office.commercial-library.index'))->assertForbidden();
        $this->actingAs($dispatcher)->get(route('office.quote-approvals.index'))->assertForbidden();
        $dispatcherMembership->capabilityOverrides()->attach(Capability::query()->where('key', 'quotes.approve')->sole(), ['effect' => 'grant']);
        $dispatcherMembership->capabilityOverrides()->attach(Capability::query()->where('key', 'proposal.templates.manage')->sole(), ['effect' => 'grant']);
        $this->actingAs($dispatcher)->get(route('office.commercial-library.index'))->assertOk();
        $this->actingAs($dispatcher)->get(route('office.quote-approvals.index'))->assertOk();
        $adminMembership = OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $admin->id)->sole();
        $adminMembership->capabilityOverrides()->attach(Capability::query()->where('key', 'quotes.approve')->sole(), ['effect' => 'deny']);
        $this->actingAs($admin)->get(route('office.quote-approvals.index'))->assertForbidden();
        $other = Organization::factory()->create();
        app(CommercialDefaults::class)->ensure($other);
        $this->actingAs($admin)->get(route('office.quotes.show', [$revision->document, $revision]).'?organization_id='.$other->id)->assertOk();
        $foreignBlock = CommercialContentBlock::query()->create(['organization_id' => $other->id, 'name' => 'Foreign', 'heading' => 'Foreign']);
        $this->actingAs($admin)->post(route('office.quotes.content-blocks.store', [$revision->document, $revision]), ['content_version' => $revision->content_version, 'content_block_id' => $foreignBlock->id])->assertNotFound();
    }

    private function context(int $costCents, int $sellCents): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$admin] = $this->member($organization, 'super_admin');
        app(CommercialDefaults::class)->ensure($organization);
        OrganizationBillingSetting::query()->create(['organization_id' => $organization->id, 'default_tax_rate_basis_points' => 0, 'default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt']);
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $stage = OpportunityStage::query()->where('organization_id', $organization->id)->where('semantic_kind', 'new')->sole();
        $opportunity = Opportunity::query()->create(['organization_id' => $organization->id, 'opportunity_number' => 'OPP-2026-0001', 'customer_id' => $customer->id, 'owner_user_id' => $admin->id, 'stage_id' => $stage->id, 'title' => 'Proposal test', 'priority' => 'normal']);
        $unit = UnitOfMeasure::query()->create(['organization_id' => $organization->id, 'code' => 'each', 'name' => 'Each', 'dimension' => 'count', 'decimal_places' => 0, 'active' => true]);
        $category = CatalogCategory::query()->create(['organization_id' => $organization->id, 'code' => 'TEST', 'name' => 'Test', 'active' => true]);
        $product = CatalogProduct::query()->create(['organization_id' => $organization->id, 'category_id' => $category->id, 'base_uom_id' => $unit->id, 'default_sales_uom_id' => $unit->id, 'product_code' => 'ITEM', 'name' => 'Proposal item', 'sales_quantity_millis' => 1000, 'default_cost_cents' => $costCents, 'default_cost_quantity_millis' => 1000, 'default_sell_price_cents' => $sellCents, 'taxable' => false, 'active' => true]);
        $revision = app(QuoteWorkflow::class)->create($opportunity, $admin, 'Customer Proposal')->revisions()->sole();
        app(QuoteWorkflow::class)->addCatalogLine($revision, $admin, ['content_version' => $revision->content_version, 'catalog_item_type' => 'product', 'catalog_item_id' => $product->id, 'quantity_millis' => 1000]);
        $revision = $revision->fresh();
        $terms = CommercialTermsSet::query()->create(['organization_id' => $organization->id, 'code' => 'STANDARD', 'name' => 'Standard terms', 'version' => 1, 'body' => 'Payment is due according to the accepted schedule.', 'approved' => true, 'active' => true, 'created_by_id' => $admin->id]);

        return [$organization, $admin, $opportunity, $revision, $terms];
    }

    private function member(Organization $organization, string $role): array
    {
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->sole());

        return [$user, $membership];
    }

    private function signaturePng(): string
    {
        $width = 300;
        $height = 120;
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0";
            for ($x = 0; $x < $width; $x++) {
                $ink = $x >= 40 && $x <= 260 && abs($y - (35 + intdiv($x, 6) % 45)) < 3;
                $raw .= $ink ? "\x0f\x17\x2a\xff" : "\xff\xff\xff\0";
            }
        }
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('H*', hash('crc32b', $type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress($raw, 9)).$chunk('IEND', '');

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function assertQueryBudget(int $maximum, Closure $request): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThanOrEqual($maximum, $count, "Commercial read query budget exceeded: {$count} queries (maximum {$maximum}).");
    }
}
