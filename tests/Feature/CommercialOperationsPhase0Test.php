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

    public function test_phase_one_exposes_only_the_opportunity_commercial_surface(): void
    {
        $this->seed(AccessControlSeeder::class);

        $routeUris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri());
        $this->assertTrue($routeUris->contains(fn (string $uri): bool => $uri === 'office/opportunities'));
        foreach (['office/quotes', 'proposals'] as $prefix) {
            $this->assertFalse(
                $routeUris->contains(fn (string $uri): bool => $uri === $prefix || str_starts_with($uri, $prefix.'/')),
                "Commercial route [{$prefix}] must not exist during Phase 0.",
            );
        }

        foreach (['organization_commercial_settings', 'opportunity_stages', 'opportunities', 'opportunity_tasks', 'opportunity_activities', 'opportunity_attachments', 'commercial_user_preferences'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Opportunity foundation table [{$table}] must exist during Phase 1.");
        }
        foreach (['commercial_documents', 'commercial_revisions', 'proposal_publications'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        $this->assertSame(3, Capability::query()->where('key', 'like', 'opportunities.%')->count());
        $this->assertFalse(Capability::query()->where(function ($query): void {
            $query->where('key', 'like', 'quotes.%')
                ->orWhere('key', 'like', 'proposal.%')
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
