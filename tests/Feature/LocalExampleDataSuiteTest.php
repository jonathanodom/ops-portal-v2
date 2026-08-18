<?php

namespace Tests\Feature;

use App\Domain\PackageDemandCalculator;
use App\Models\CatalogPackage;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PaymentProviderConfiguration;
use App\Models\Role;
use App\Models\User;
use App\Models\VisitTimeEntry;
use App\Support\LocalExamples\LocalExampleBootstrapper;
use App\Support\LocalExamples\LocalExampleGuard;
use App\Support\LocalExamples\LocalExampleInventory;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class LocalExampleDataSuiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Storage::fake('local');
    }

    public function test_environment_guard_refuses_the_test_database_without_an_explicit_internal_bypass(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=local');

        app(LocalExampleGuard::class)->environment();
    }

    public function test_small_profile_is_deterministic_idempotent_and_preserves_configuration(): void
    {
        config()->set('local_examples.allow_testing', true);
        [$organization] = $this->organizationWithSuperAdmin();
        PaymentProviderConfiguration::query()->create([
            'organization_id' => $organization->id,
            'public_id' => (string) Str::uuid(),
            'provider' => 'stripe',
            'environment' => 'test',
            'enabled' => false,
            'connection_status' => 'untested',
        ]);

        $bootstrapper = app(LocalExampleBootstrapper::class);
        $this->assertSame(LocalExampleInventory::SMALL_EXPECTED, $bootstrapper->bootstrap($organization, 'small'));
        $this->assertSame('complete', app(LocalExampleInventory::class)->status($organization, 'small'));
        $this->assertSame(LocalExampleInventory::SMALL_EXPECTED, $bootstrapper->bootstrap($organization, 'small'));
        $this->assertSame(1, PaymentProviderConfiguration::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(0, VisitTimeEntry::query()->where('organization_id', $organization->id)->whereNotNull('active_user_id')->count());
        $this->assertDatabaseHas('visit_time_entries', ['organization_id' => $organization->id, 'source' => 'system_auto']);
        $this->assertDatabaseHas('customer_service_enrollments', ['organization_id' => $organization->id, 'status' => 'active']);
        $this->assertDatabaseHas('customer_service_enrollments', ['organization_id' => $organization->id, 'status' => 'paused']);
        $this->assertDatabaseHas('service_ticket_files', ['organization_id' => $organization->id, 'mime_type' => 'application/pdf']);
        $this->assertDatabaseHas('service_ticket_files', ['organization_id' => $organization->id, 'mime_type' => 'image/png']);
        $this->assertDatabaseHas('closeouts', ['organization_id' => $organization->id, 'outcome' => 'on_hold']);
        $this->assertDatabaseHas('payment_transactions', ['organization_id' => $organization->id, 'method' => 'cash', 'status' => 'succeeded']);
        $this->assertDatabaseHas('payment_transactions', ['organization_id' => $organization->id, 'method' => 'check', 'status' => 'succeeded']);

        $package = CatalogPackage::query()->where('organization_id', $organization->id)
            ->where('package_code', 'EXAMPLE-SMART-HOME-ROUGH-IN')->firstOrFail();
        $demand = app(PackageDemandCalculator::class)->calculate($package, 5000)['products']->keyBy('product_code');
        $this->assertSame(1750000, $demand['EXAMPLE-CAT6-BLUE']['standard_quantity_millis']);
        $this->assertSame(1750000, $demand['EXAMPLE-CAT6-YELLOW']['standard_quantity_millis']);
        $this->assertSame(875000, $demand['EXAMPLE-WIRE-16-2']['standard_quantity_millis']);
        $this->assertSame(875000, $demand['EXAMPLE-WIRE-16-4']['standard_quantity_millis']);
    }

    public function test_partial_operational_data_is_refused_and_another_organization_is_untouched(): void
    {
        config()->set('local_examples.allow_testing', true);
        [$organization] = $this->organizationWithSuperAdmin();
        [$other] = $this->organizationWithSuperAdmin();
        $other->customers()->create([
            'display_name' => 'Preserved foreign customer',
            'type' => 'business',
            'status' => 'active',
        ]);
        $organization->customers()->create([
            'display_name' => 'Existing operational customer',
            'type' => 'business',
            'status' => 'active',
        ]);

        $this->expectException(RuntimeException::class);
        try {
            app(LocalExampleBootstrapper::class)->bootstrap($organization, 'small');
        } finally {
            $this->assertDatabaseHas('customers', ['organization_id' => $other->id, 'display_name' => 'Preserved foreign customer']);
            $this->assertDatabaseMissing('catalog_services', ['organization_id' => $other->id, 'service_code' => 'EXAMPLE-TV-MOUNT']);
        }
    }

    public function test_full_profile_meets_its_exact_volume_contract(): void
    {
        config()->set('local_examples.allow_testing', true);
        [$organization] = $this->organizationWithSuperAdmin();

        app(LocalExampleBootstrapper::class)->bootstrap($organization, 'full');
        $inventory = app(LocalExampleInventory::class);

        $this->assertSame('complete', $inventory->status($organization, 'full'));
        $this->assertSame(LocalExampleInventory::FULL_EXPECTED, $inventory->inspect($organization)['exampleCounts']);
        $this->assertSame(0, VisitTimeEntry::query()->where('organization_id', $organization->id)->whereNotNull('active_user_id')->count());
    }

    /** @return array{Organization, User, OrganizationMembership} */
    private function organizationWithSuperAdmin(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'super_admin')->firstOrFail());

        return [$organization, $user, $membership];
    }
}
