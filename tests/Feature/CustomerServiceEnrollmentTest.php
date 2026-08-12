<?php

namespace Tests\Feature;

use App\Domain\CatalogDefaults;
use App\Models\AuditEvent;
use App\Models\BillingHandoff;
use App\Models\Capability;
use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;
use App\Models\Customer;
use App\Models\CustomerServiceEnrollment;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PaymentAttempt;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerServiceEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_super_admin_enrolls_customer_with_immutable_recurring_snapshot_without_automation(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        [$customer, $location] = $this->customer($organization);
        [$service, $variant] = $this->recurringService($organization);

        $this->actingAs($admin)->post("/office/customers/{$customer->id}/subscriptions", [
            'catalog_service_id' => $service->id,
            'catalog_service_variant_id' => $variant->id,
            'service_location_id' => $location->id,
            'start_date' => '2026-09-01',
            'next_billing_date' => '2026-09-01',
            'internal_notes' => 'Customer requested quarterly health checks.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $enrollment = CustomerServiceEnrollment::query()->firstOrFail();
        $this->assertSame(7900, $enrollment->billing_amount_cents);
        $this->assertSame('NDT-ADV', $enrollment->service_code_snapshot);
        $this->assertSame('NewDay Advantage Plus', $enrollment->service_name_snapshot);
        $this->assertSame('Priority', $enrollment->variant_label_snapshot);
        $this->assertSame('month', $enrollment->billing_cadence);
        $this->assertSame(1, $enrollment->billing_interval);
        $this->assertTrue($enrollment->taxable_snapshot);
        $this->assertNotNull($enrollment->current_scope_key);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, BillingHandoff::query()->count());
        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(0, ServiceTicket::query()->count());
        $metadata = AuditEvent::query()->where('event_type', 'subscription.created')->firstOrFail()->metadata;
        $this->assertStringNotContainsString('quarterly health checks', json_encode($metadata, JSON_THROW_ON_ERROR));

        $service->update(['name' => 'Renamed plan', 'default_price_cents' => 9900]);
        $variant->update(['customer_label' => 'Renamed tier', 'price_override_cents' => 10900]);
        $this->assertSame('NewDay Advantage Plus', $enrollment->fresh()->service_name_snapshot);
        $this->assertSame('Priority', $enrollment->fresh()->variant_label_snapshot);
        $this->assertSame(7900, $enrollment->fresh()->billing_amount_cents);
    }

    public function test_enrollment_sources_and_duplicate_current_scope_are_validated_transactionally(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        [$customer, $location] = $this->customer($organization);
        [$service, $variant] = $this->recurringService($organization);
        [, , $other] = $this->userWithRole('super_admin');
        [, $foreignLocation] = $this->customer($other);
        [$foreignService, $foreignVariant] = $this->recurringService($other, 'FOREIGN');

        $base = ['catalog_service_id' => $service->id, 'service_location_id' => $location->id, 'start_date' => '2026-09-01'];
        $this->actingAs($admin)->post("/office/customers/{$customer->id}/subscriptions", array_merge($base, ['catalog_service_variant_id' => $foreignVariant->id]))->assertSessionHasErrors('catalog_service_variant_id');
        $this->actingAs($admin)->post("/office/customers/{$customer->id}/subscriptions", array_merge($base, ['service_location_id' => $foreignLocation->id]))->assertSessionHasErrors('service_location_id');
        $this->actingAs($admin)->post("/office/customers/{$customer->id}/subscriptions", array_merge($base, ['catalog_service_id' => $foreignService->id]))->assertSessionHasErrors('catalog_service_id');
        $this->assertSame(0, CustomerServiceEnrollment::query()->count());

        $this->actingAs($admin)->post("/office/customers/{$customer->id}/subscriptions", array_merge($base, ['catalog_service_variant_id' => $variant->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/office/customers/{$customer->id}/subscriptions", array_merge($base, ['catalog_service_variant_id' => $variant->id]))->assertSessionHasErrors('catalog_service_id');
        $this->assertSame(1, CustomerServiceEnrollment::query()->count());
    }

    public function test_pause_resume_cancel_preserves_history_and_canceled_enrollment_is_immutable(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        [$customer, $location] = $this->customer($organization);
        [$service] = $this->recurringService($organization);
        $this->actingAs($admin)->post("/office/customers/{$customer->id}/subscriptions", ['catalog_service_id' => $service->id, 'service_location_id' => $location->id, 'start_date' => '2026-09-01'])->assertRedirect();
        $enrollment = CustomerServiceEnrollment::query()->firstOrFail();

        $this->actingAs($admin)->post("/office/subscriptions/{$enrollment->id}/transition", ['status' => 'paused', 'confirmation' => '1'])->assertRedirect();
        $this->assertSame('paused', $enrollment->fresh()->status);
        $this->assertNotNull($enrollment->fresh()->current_scope_key);
        $this->actingAs($admin)->post("/office/subscriptions/{$enrollment->id}/transition", ['status' => 'active', 'confirmation' => '1'])->assertRedirect();
        $this->actingAs($admin)->post("/office/subscriptions/{$enrollment->id}/transition", ['status' => 'canceled', 'confirmation' => '1'])->assertRedirect();
        $enrollment->refresh();
        $this->assertSame('canceled', $enrollment->status);
        $this->assertNull($enrollment->current_scope_key);
        $this->assertNotNull($enrollment->canceled_at);
        $this->actingAs($admin)->put("/office/subscriptions/{$enrollment->id}", ['start_date' => '2026-09-02', 'billing_amount' => '69.00'])->assertSessionHasErrors('status');

        $this->actingAs($admin)->post("/office/customers/{$customer->id}/subscriptions", ['catalog_service_id' => $service->id, 'service_location_id' => $location->id, 'start_date' => '2026-10-01'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, CustomerServiceEnrollment::query()->count());
    }

    public function test_amount_override_requires_reason_and_audit_omits_reason_contents(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        [$customer] = $this->customer($organization);
        [$service] = $this->recurringService($organization);
        $this->actingAs($admin)->post("/office/customers/{$customer->id}/subscriptions", ['catalog_service_id' => $service->id, 'start_date' => '2026-09-01', 'billing_amount' => '65.00'])->assertSessionHasErrors('billing_amount_reason');
        $reason = 'Founding customer negotiated rate';
        $this->actingAs($admin)->post("/office/customers/{$customer->id}/subscriptions", ['catalog_service_id' => $service->id, 'start_date' => '2026-09-01', 'billing_amount' => '65.00', 'billing_amount_reason' => $reason])->assertRedirect();
        $enrollment = CustomerServiceEnrollment::query()->firstOrFail();
        $this->assertSame(6500, $enrollment->billing_amount_cents);
        $this->assertSame($reason, $enrollment->billing_amount_override_reason);
        $metadata = AuditEvent::query()->where('event_type', 'subscription.created')->firstOrFail()->metadata;
        $this->assertStringNotContainsString($reason, json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    public function test_roles_overrides_inactive_memberships_and_organization_scope_are_enforced(): void
    {
        [$dispatcher, $dispatcherMembership, $organization] = $this->userWithRole('dispatcher');
        [$customer] = $this->customer($organization);
        [$service] = $this->recurringService($organization);
        $this->actingAs($dispatcher)->get('/office/subscriptions')->assertOk();
        $this->actingAs($dispatcher)->get("/office/customers/{$customer->id}/subscriptions/create")->assertOk();

        [$reviewer, , $reviewerOrganization] = $this->userWithRole('reviewer');
        [$reviewerCustomer] = $this->customer($reviewerOrganization);
        $this->actingAs($reviewer)->get('/office/subscriptions')->assertOk();
        $this->actingAs($reviewer)->get("/office/customers/{$reviewerCustomer->id}/subscriptions/create")->assertForbidden();
        [$billing] = $this->userWithRole('billing');
        $this->actingAs($billing)->get('/office/subscriptions')->assertOk();
        [$technician] = $this->userWithRole('technician');
        $this->actingAs($technician)->get('/office/subscriptions')->assertForbidden();

        $manage = Capability::query()->where('key', 'subscriptions.manage')->firstOrFail();
        $dispatcherMembership->capabilityOverrides()->attach($manage, ['effect' => 'deny']);
        $this->actingAs($dispatcher)->post("/office/customers/{$customer->id}/subscriptions", ['catalog_service_id' => $service->id, 'start_date' => '2026-09-01'])->assertForbidden();
        $dispatcherMembership->update(['status' => 'inactive']);
        $this->actingAs($dispatcher)->get('/office/subscriptions')->assertForbidden();

        [$admin, , $other] = $this->userWithRole('super_admin');
        [$otherCustomer] = $this->customer($other);
        [$otherService] = $this->recurringService($other, 'OTHER');
        $this->actingAs($admin)->post("/office/customers/{$otherCustomer->id}/subscriptions", ['catalog_service_id' => $otherService->id, 'start_date' => '2026-09-01'])->assertRedirect();
        $foreignEnrollment = CustomerServiceEnrollment::query()->forOrganization($other->id)->firstOrFail();
        $this->actingAs($reviewer)->get("/office/subscriptions/{$foreignEnrollment->id}")->assertNotFound();
    }

    public function test_current_enrollments_block_customer_and_location_archival_and_ui_is_accessible(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        [$customer, $location] = $this->customer($organization);
        [$service] = $this->recurringService($organization);
        $this->actingAs($admin)->post("/office/customers/{$customer->id}/subscriptions", ['catalog_service_id' => $service->id, 'service_location_id' => $location->id, 'start_date' => '2026-09-01'])->assertRedirect();
        $enrollment = CustomerServiceEnrollment::query()->firstOrFail();

        $customerPayload = ['type' => 'business', 'display_name' => $customer->display_name, 'status' => 'inactive'];
        $this->actingAs($admin)->put("/office/customers/{$customer->id}", $customerPayload)->assertSessionHasErrors('status');
        $locationPayload = ['name' => $location->name, 'address_line_1' => '100 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'timezone' => 'America/Chicago', 'active' => '0'];
        $this->actingAs($admin)->put("/office/locations/{$location->id}", $locationPayload)->assertSessionHasErrors('active');

        $this->actingAs($admin)->get('/office/subscriptions')->assertOk()->assertSee('data-office-width="workspace"', false)->assertSee('office-mobile-list');
        $this->actingAs($admin)->get("/office/subscriptions/{$enrollment->id}")->assertOk()->assertSee('data-office-width="detail"', false)->assertSee('Automation boundary')->assertSee('min-h-11', false);
        $this->actingAs($admin)->get("/office/customers/{$customer->id}")->assertOk()->assertSee('id="customer-services"', false)->assertSee('Add recurring Service');
        $this->actingAs($admin)->get("/office/customers/{$customer->id}/subscriptions/create")->assertOk()->assertSee('data-office-width="form"', false)->assertSee('Tracking foundation only.')->assertSee('data-subscription-variant', false);
    }

    /** @return array{User, OrganizationMembership, Organization} */
    private function userWithRole(string $roleKey): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $membership, $organization];
    }

    /** @return array{Customer, ServiceLocation} */
    private function customer(Organization $organization): array
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'active' => true]);

        return [$customer, $location];
    }

    /** @return array{CatalogService, CatalogServiceVariant} */
    private function recurringService(Organization $organization, string $code = 'NDT-ADV'): array
    {
        app(CatalogDefaults::class)->ensureFor($organization);
        $unit = UnitOfMeasure::query()->forOrganization($organization->id)->where('code', 'month')->firstOrFail();
        $service = CatalogService::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $unit->id, 'service_code' => $code, 'name' => 'NewDay Advantage Plus', 'customer_description' => 'Managed technology care.', 'pricing_model' => 'recurring', 'default_price_cents' => 6900, 'billing_cadence' => 'month', 'billing_interval' => 1, 'taxable' => true, 'active' => true]);
        $variant = CatalogServiceVariant::query()->create(['organization_id' => $organization->id, 'catalog_service_id' => $service->id, 'code' => 'PRIORITY', 'label' => 'Priority', 'customer_label' => 'Priority', 'price_override_cents' => 7900, 'active' => true]);

        return [$service, $variant];
    }
}
