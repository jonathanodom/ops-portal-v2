<?php

namespace Tests\Feature;

use App\Http\Middleware\RecordOperationalFailures;
use App\Models\Capability;
use App\Models\OperationalIncident;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\IncidentRecorder;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\BetaScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BetaHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_incidents_deduplicate_and_drop_sensitive_context(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $recorder = app(IncidentRecorder::class);
        $first = $recorder->record($organization, $user, 'upload_failure', 'error', $organization, [
            'route' => 'field.visits.media.store', 'media_id' => 10, 'address' => 'private', 'exception' => 'secret',
        ], '4480d1dc-814a-4b94-80ba-cc31fb675f22');
        $second = $recorder->record($organization, $user, 'upload_failure', 'error', $organization, [
            'route' => 'field.visits.media.store', 'media_id' => 10, 'notes' => 'private',
        ], '4480d1dc-814a-4b94-80ba-cc31fb675f22');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->fresh()->occurrences);
        $this->assertSame(['route' => 'field.visits.media.store', 'media_id' => 10], $second->fresh()->context);
        $this->assertDatabaseCount('operational_incidents', 1);
    }

    public function test_transition_and_storage_failures_capture_only_safe_diagnostics(): void
    {
        $middleware = app(RecordOperationalFailures::class);
        $transition = Request::create('/field/visits/10/transition', 'POST', ['reason' => 'private reason']);
        $transitionRoute = new Route(['POST'], '/field/visits/{visit}/transition', fn () => null);
        $transitionRoute->name('field.visits.transition');
        $transitionRoute->bind($transition);
        $transition->setRouteResolver(fn () => $transitionRoute);
        try {
            $middleware->handle($transition, fn () => throw ValidationException::withMessages(['reason' => 'Invalid private reason.']));
        } catch (ValidationException) {
            // Expected: middleware records then preserves normal validation handling.
        }

        $storage = Request::create('/field-media/22', 'GET');
        $storageRoute = new Route(['GET'], '/field-media/{media}', fn () => null);
        $storageRoute->name('field.media.show');
        $storageRoute->bind($storage);
        $storage->setRouteResolver(fn () => $storageRoute);
        try {
            $middleware->handle($storage, fn () => throw new \RuntimeException('raw private storage error'));
        } catch (\RuntimeException) {
            // Expected: middleware records then rethrows the original failure.
        }

        $transitionIncident = OperationalIncident::query()->where('category', 'transition_rejected')->firstOrFail();
        $storageIncident = OperationalIncident::query()->where('category', 'storage_failure')->firstOrFail();
        $this->assertEquals(['route' => 'field.visits.transition', 'invalid_fields' => ['reason'], 'visit_id' => 10], $transitionIncident->context);
        $this->assertEquals(['route' => 'field.media.show', 'reason_code' => 'RuntimeException', 'media_id' => 22], $storageIncident->context);
        $this->assertStringNotContainsString('private', json_encode([$transitionIncident->context, $storageIncident->context], JSON_THROW_ON_ERROR));
    }

    public function test_health_screen_is_super_admin_only_and_resolutions_are_audited(): void
    {
        $organization = Organization::factory()->create();
        [$admin, $adminMembership] = $this->userWithRole('super_admin', $organization);
        [$dispatcher] = $this->userWithRole('dispatcher', $organization);
        $incident = OperationalIncident::query()->create([
            'organization_id' => $organization->id, 'category' => 'stuck_visit', 'severity' => 'warning',
            'fingerprint' => hash('sha256', 'health-test'), 'status' => 'open', 'occurrences' => 1,
            'first_occurred_at' => now(), 'last_occurred_at' => now(), 'context' => ['visit_id' => 12],
        ]);

        $this->actingAs($admin)->get('/office/operations/health')->assertOk()->assertSee('Operational health')->assertHeader('X-Request-ID');
        $this->actingAs($dispatcher)->get('/office/operations/health')->assertForbidden();
        $this->actingAs($admin)->post("/office/operations/incidents/{$incident->id}/resolve")->assertRedirect();
        $this->assertDatabaseHas('operational_incidents', ['id' => $incident->id, 'status' => 'resolved', 'resolved_by_id' => $admin->id]);
        $this->assertDatabaseHas('audit_events', ['organization_id' => $organization->id, 'event_type' => 'operational_incident.resolved']);

        $view = Capability::query()->where('key', 'operations.health.view')->firstOrFail();
        $adminMembership->capabilityOverrides()->syncWithoutDetaching([$view->id => ['effect' => 'deny']]);
        $this->actingAs($admin)->get('/office/operations/health')->assertForbidden();
    }

    public function test_health_actions_cannot_cross_organization_scope(): void
    {
        $organization = Organization::factory()->create();
        [$admin] = $this->userWithRole('super_admin', $organization);
        $otherOrganization = Organization::factory()->create();
        $incident = OperationalIncident::query()->create([
            'organization_id' => $otherOrganization->id, 'category' => 'stuck_visit', 'severity' => 'warning',
            'fingerprint' => hash('sha256', 'cross-organization-health'), 'status' => 'open', 'occurrences' => 1,
            'first_occurred_at' => now(), 'last_occurred_at' => now(),
        ]);

        $this->actingAs($admin)->get('/office/operations/health')->assertOk()->assertDontSee('cross-organization-health');
        $this->actingAs($admin)->post("/office/operations/incidents/{$incident->id}/resolve")->assertNotFound();
        $this->assertDatabaseHas('operational_incidents', ['id' => $incident->id, 'status' => 'open']);
    }

    public function test_every_request_receives_a_safe_correlation_id(): void
    {
        $this->get('/login', ['X-Request-ID' => 'not-a-uuid'])
            ->assertOk()
            ->assertHeader('X-Request-ID');

        $requestId = '4480d1dc-814a-4b94-80ba-cc31fb675f22';
        $this->get('/login', ['X-Request-ID' => $requestId])
            ->assertHeader('X-Request-ID', $requestId);
    }

    public function test_beta_reset_refuses_non_beta_environment(): void
    {
        $this->artisan('beta:setup')->assertFailed();
        $this->assertDatabaseHas('roles', ['key' => 'super_admin']);
    }

    public function test_small_launch_fixture_is_deterministic_and_queues_stay_bounded(): void
    {
        Storage::fake('local');
        putenv('BETA_DEMO_PASSWORD=BetaFixturePassword123!');
        try {
            $this->seed(BetaScenarioSeeder::class);
        } finally {
            putenv('BETA_DEMO_PASSWORD');
        }

        foreach (['customers' => 250, 'service_locations' => 400, 'service_tickets' => 500, 'visits' => 1000, 'closeouts' => 200, 'visit_media' => 500] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame(3, DB::table('service_tickets')->where('title', 'like', 'BETA %:%')->count());
        $this->assertSame(5, DB::table('organization_membership_role')->count());
        $this->assertSame(1, DB::table('invoices')->where('status', 'issued')->count());
        $this->assertSame(1, DB::table('invoices')->where('status', 'draft')->count());
        $this->assertContains('visits_dispatch_queue', array_column(Schema::getIndexes('visits'), 'name'));
        $this->assertContains('visit_assignments_queue', array_column(Schema::getIndexes('visit_assignments'), 'name'));
        $this->assertContains('incidents_health_queue', array_column(Schema::getIndexes('operational_incidents'), 'name'));

        $admin = User::query()->where('email', 'beta.super_admin@newdaytech.test')->firstOrFail();
        foreach (['/field' => 20, '/office/dispatch' => 25, '/office/service-tickets' => 20, '/office/closeout-reviews' => 20] as $uri => $budget) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get($uri)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertLessThanOrEqual($budget, $queries, "{$uri} used {$queries} queries");
        }
    }

    public function test_sqlite_backup_is_verified_in_an_isolated_copy(): void
    {
        $path = database_path('restore-command-beta-test.sqlite');
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE proof (id INTEGER PRIMARY KEY)');
        $pdo = null;
        try {
            $this->artisan('ops:restore-verify', ['backup' => $path])->assertSuccessful()->expectsOutputToContain('Integrity: ok');
        } finally {
            @unlink($path);
        }
    }

    private function userWithRole(string $roleKey, Organization $organization): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $membership];
    }
}
