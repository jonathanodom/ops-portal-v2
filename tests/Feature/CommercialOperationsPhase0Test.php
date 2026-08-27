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

    public function test_phase_two_exposes_quote_foundation_but_no_proposal_surface(): void
    {
        $this->seed(AccessControlSeeder::class);

        $routeUris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri());
        $this->assertTrue($routeUris->contains(fn (string $uri): bool => $uri === 'office/opportunities'));
        foreach (['proposals'] as $prefix) {
            $this->assertFalse(
                $routeUris->contains(fn (string $uri): bool => $uri === $prefix || str_starts_with($uri, $prefix.'/')),
                "Commercial route [{$prefix}] must not exist during Phase 2.",
            );
        }

        foreach (['organization_commercial_settings', 'opportunity_stages', 'opportunities', 'opportunity_tasks', 'opportunity_activities', 'opportunity_attachments', 'commercial_user_preferences'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Opportunity foundation table [{$table}] must exist during Phase 1.");
        }
        foreach (['commercial_documents', 'commercial_revisions', 'commercial_revision_locations', 'commercial_revision_systems', 'commercial_revision_phases', 'commercial_revision_sections', 'commercial_revision_lines', 'commercial_revision_line_components', 'commercial_payment_milestones'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Quote foundation table [{$table}] must exist during Phase 2.");
        }
        $this->assertFalse(Schema::hasTable('proposal_publications'));

        $this->assertSame(3, Capability::query()->where('key', 'like', 'opportunities.%')->count());
        $this->assertSame(3, Capability::query()->where('key', 'like', 'quotes.%')->count());
        $this->assertFalse(Capability::query()->where(function ($query): void {
            $query->where('key', 'like', 'proposal.%')
                ->orWhere('key', 'like', 'commercial.%')
                ->orWhere('key', 'like', 'change_orders.%');
        })->exists());
    }

    public function test_private_storage_is_isolated_from_retained_files_during_tests(): void
    {
        $testPath = str_replace('\\', '/', Storage::disk('local')->path('phase-zero-probe.txt'));
        $retainedPath = str_replace('\\', '/', storage_path('app/private'));

        $this->assertStringNotContainsString($retainedPath, $testPath);
        $this->assertStringContainsString('/storage/framework/testing/disks/local/', $testPath);
    }
}
