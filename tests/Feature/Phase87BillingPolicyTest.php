<?php

namespace Tests\Feature;

use App\Domain\NewDayCatalogBootstrap;
use App\Models\AuditEvent;
use App\Models\BillingLaborRate;
use App\Models\Capability;
use App\Models\CatalogService;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase87BillingPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_billing_policy_selects_organization_catalog_services_and_persists_calculation_rules(): void
    {
        [$organization, $admin] = $this->actor();
        $bootstrap = app(NewDayCatalogBootstrap::class);
        $bootstrap->ensureLaborServices($organization, $admin);
        $bootstrap->ensureTripCharge($organization, $admin);
        $labor = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->firstOrFail();
        $trip = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'TRIP')->firstOrFail();

        $this->actingAs($admin)->put('/office/settings/billing/labor-policy', [
            'default_labor_catalog_service_id' => $labor->id,
            'labor_billing_increment_minutes' => 30,
            'labor_rounding_rule' => 'nearest',
            'minimum_billable_minutes' => 60,
            'trip_charge_catalog_service_id' => $trip->id,
            'suggest_trip_charges' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $settings = OrganizationBillingSetting::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame($labor->id, $settings->default_labor_catalog_service_id);
        $this->assertSame(30, $settings->labor_billing_increment_minutes);
        $this->assertSame('nearest', $settings->labor_rounding_rule);
        $this->assertSame(60, $settings->minimum_billable_minutes);
        $this->assertSame($trip->id, $settings->trip_charge_catalog_service_id);
        $this->assertTrue($settings->suggest_trip_charges);
        $this->assertFalse($settings->auto_select_trip_charges);
        $audit = AuditEvent::query()->where('event_type', 'billing.labor_policy_updated')->latest('occurred_at')->firstOrFail();
        $this->assertContains('default_labor_catalog_service_id', $audit->metadata['changed_fields']);
        $this->assertArrayNotHasKey('default_labor_service_name', $audit->metadata);
        $this->assertStringNotContainsString($labor->name, json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_policy_rejects_foreign_or_structurally_invalid_catalog_services(): void
    {
        [$organization, $admin] = $this->actor();
        [$other] = $this->actor();
        $bootstrap = app(NewDayCatalogBootstrap::class);
        $bootstrap->ensureLaborServices($organization);
        $bootstrap->ensureTripCharge($organization);
        $bootstrap->ensureLaborServices($other);
        $foreignLabor = CatalogService::query()->forOrganization($other->id)->where('service_code', 'LABOR-BUS')->firstOrFail();
        $invalidTrip = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-RES-IT')->firstOrFail();

        $this->actingAs($admin)->put('/office/settings/billing/labor-policy', $this->payload($foreignLabor->id, $invalidTrip->id))
            ->assertSessionHasErrors(['default_labor_catalog_service_id']);
        $this->assertDatabaseMissing('organization_billing_settings', [
            'organization_id' => $organization->id,
            'default_labor_catalog_service_id' => $foreignLabor->id,
        ]);

        $validLabor = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->firstOrFail();
        $this->actingAs($admin)->put('/office/settings/billing/labor-policy', $this->payload($validLabor->id, $invalidTrip->id))
            ->assertSessionHasErrors(['trip_charge_catalog_service_id']);
    }

    public function test_auto_selection_requires_suggestions_and_trip_configuration(): void
    {
        [$organization, $admin] = $this->actor();
        $bootstrap = app(NewDayCatalogBootstrap::class);
        $bootstrap->ensureLaborServices($organization);
        $labor = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->firstOrFail();

        $this->actingAs($admin)->put('/office/settings/billing/labor-policy', [
            'default_labor_catalog_service_id' => $labor->id,
            'labor_billing_increment_minutes' => 15,
            'labor_rounding_rule' => 'up',
            'minimum_billable_minutes' => 0,
            'auto_select_trip_charges' => '1',
        ])->assertSessionHasErrors('auto_select_trip_charges');

        $this->actingAs($admin)->put('/office/settings/billing/labor-policy', [
            'default_labor_catalog_service_id' => $labor->id,
            'labor_billing_increment_minutes' => 15,
            'labor_rounding_rule' => 'up',
            'minimum_billable_minutes' => 0,
            'suggest_trip_charges' => '1',
        ])->assertSessionHasErrors('trip_charge_catalog_service_id');
    }

    public function test_billing_settings_replaces_editable_named_rates_with_catalog_policy_and_enforces_capability(): void
    {
        [$organization, $admin, $membership] = $this->actor();
        BillingLaborRate::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Historical Standard',
            'hourly_rate_cents' => 12500,
            'is_default' => true,
            'active' => true,
        ]);

        $this->actingAs($admin)->get('/office/settings/billing')
            ->assertOk()
            ->assertSee('Labor billing policy')
            ->assertSee('Legacy labor rates retained')
            ->assertSee('Manage labor services')
            ->assertDontSee('Named labor rates')
            ->assertDontSee('Add rate');

        $membership->capabilityOverrides()->attach(Capability::query()->where('key', 'billing.settings.manage')->firstOrFail(), ['effect' => 'deny']);
        $this->actingAs($admin)->put('/office/settings/billing/labor-policy', [])->assertForbidden();
    }

    /** @return array{Organization, User, OrganizationMembership} */
    private function actor(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'super_admin')->firstOrFail());

        return [$organization, $user, $membership];
    }

    /** @return array<string, mixed> */
    private function payload(int $laborId, int $tripId): array
    {
        return [
            'default_labor_catalog_service_id' => $laborId,
            'labor_billing_increment_minutes' => 15,
            'labor_rounding_rule' => 'up',
            'minimum_billable_minutes' => 0,
            'trip_charge_catalog_service_id' => $tripId,
            'suggest_trip_charges' => '1',
        ];
    }
}
