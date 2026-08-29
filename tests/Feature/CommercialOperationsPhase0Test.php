<?php

namespace Tests\Feature;

use App\Models\Capability;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommercialOperationsPhase0Test extends TestCase
{
    use RefreshDatabase;

    public function test_phase_five_exposes_the_public_customer_surface_and_additive_acceptance_schema(): void
    {
        $this->seed(AccessControlSeeder::class);

        $routeUris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri());
        $this->assertTrue($routeUris->contains(fn (string $uri): bool => $uri === 'office/opportunities'));
        $this->assertTrue($routeUris->contains(fn (string $uri): bool => $uri === 'proposals/{token}'));

        foreach (['organization_commercial_settings', 'opportunity_stages', 'opportunities', 'opportunity_tasks', 'opportunity_activities', 'opportunity_attachments', 'commercial_user_preferences'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Opportunity foundation table [{$table}] must exist during Phase 1.");
        }
        foreach (['commercial_documents', 'commercial_revisions', 'commercial_revision_locations', 'commercial_revision_systems', 'commercial_revision_phases', 'commercial_revision_sections', 'commercial_revision_lines', 'commercial_revision_line_components', 'commercial_payment_milestones'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Quote foundation table [{$table}] must exist during Phase 2.");
        }
        foreach (['commercial_content_blocks', 'commercial_terms_sets', 'proposal_templates', 'proposal_template_sections', 'commercial_revision_media', 'commercial_revision_approvals', 'proposal_publications', 'proposal_recipients', 'proposal_share_links', 'proposal_delivery_attempts'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Phase 4 publication table [{$table}] must exist.");
        }
        foreach (['proposal_engagement_events', 'proposal_comments', 'proposal_option_selections', 'proposal_email_verifications', 'proposal_acceptances', 'proposal_acceptance_line_selections', 'accepted_payment_milestones'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Phase 5 response table [{$table}] must exist.");
        }

        $this->assertSame(3, Capability::query()->where('key', 'like', 'opportunities.%')->count());
        $this->assertSame(5, Capability::query()->where('key', 'like', 'quotes.%')->count());
        $this->assertSame(2, Capability::query()->where('key', 'like', 'proposal.%')->count());
        $this->assertFalse(Capability::query()->where('key', 'like', 'commercial.%')->orWhere('key', 'like', 'change_orders.%')->exists());
    }

    public function test_private_storage_is_isolated_from_retained_files_during_tests(): void
    {
        $testPath = str_replace('\\', '/', Storage::disk('local')->path('phase-zero-probe.txt'));
        $retainedPath = str_replace('\\', '/', storage_path('app/private'));

        $this->assertStringNotContainsString($retainedPath, $testPath);
        $this->assertStringContainsString('/storage/framework/testing/disks/local/', $testPath);
    }
}
