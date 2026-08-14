<?php

namespace Tests\Feature;

use App\Models\Capability;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DirectInvoiceCreationUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_billing_user_can_search_and_create_direct_draft_from_invoice_workspace(): void
    {
        [$billing, $organization] = $this->userWithRole('billing');
        [$customer, $contact, $location] = $this->customerGraph($organization);
        $token = (string) Str::uuid();

        $this->actingAs($billing)->get('/office/invoices')
            ->assertOk()
            ->assertSee('New invoice')
            ->assertSee('href="'.route('office.invoices.create').'"', false);
        $this->actingAs($billing)->get('/office/invoices/create')
            ->assertOk()
            ->assertSee('data-customer-picker', false)
            ->assertSee(route('office.invoices.customer-options'))
            ->assertSee('Billing contact')
            ->assertSee('Create draft');
        $this->actingAs($billing)->getJson('/office/invoices/customer-options?q=8175550100')
            ->assertOk()
            ->assertJsonPath('customers.0.id', $customer->id)
            ->assertJsonPath('customers.0.locations.0.id', $location->id)
            ->assertJsonPath('customers.0.contacts.0.id', $contact->id);

        $response = $this->actingAs($billing)->post('/office/invoices', [
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'contact_id' => $contact->id,
            'creation_token' => $token,
            'organization_id' => Organization::factory()->create()->id,
        ]);
        $invoice = Invoice::query()->forOrganization($organization->id)->sole();
        $response->assertRedirect(route('office.invoices.show', $invoice));
        $this->assertTrue($invoice->isDirect());
        $this->assertSame($contact->name, $invoice->billing_contact_name);

        $this->actingAs($billing)->post('/office/invoices', [
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'contact_id' => $contact->id,
            'creation_token' => $token,
        ])->assertRedirect(route('office.invoices.show', $invoice));
        $this->assertSame(1, Invoice::query()->forOrganization($organization->id)->count());
    }

    public function test_direct_invoice_validation_restores_selected_customer_without_creating_partial_data(): void
    {
        [$billing, $organization] = $this->userWithRole('billing');
        [$customer, , $location] = $this->customerGraph($organization);
        [, $foreignOrganization] = $this->userWithRole('billing');
        [, , $foreignLocation] = $this->customerGraph($foreignOrganization);

        $this->actingAs($billing)->from('/office/invoices/create')->post('/office/invoices', [
            'customer_id' => $customer->id,
            'service_location_id' => $foreignLocation->id,
            'creation_token' => (string) Str::uuid(),
        ])->assertRedirect('/office/invoices/create')->assertSessionHasErrors('service_location_id');

        $this->actingAs($billing)->get('/office/invoices/create')
            ->assertOk()
            ->assertSee($customer->display_name)
            ->assertSee('value="'.$customer->id.'"', false)
            ->assertSee($location->name);
        $this->assertSame(0, Invoice::query()->forOrganization($organization->id)->count());
    }

    public function test_direct_invoice_routes_enforce_manage_capability_active_membership_and_safe_projection(): void
    {
        [$reviewer, $organization] = $this->userWithRole('reviewer');
        [$customer] = $this->customerGraph($organization);
        $customer->update(['notes' => 'PRIVATE CUSTOMER NOTES']);

        $this->actingAs($reviewer)->get('/office/invoices')->assertOk()->assertDontSee('New invoice');
        $this->actingAs($reviewer)->get('/office/invoices/create')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/office/invoices/customer-options?q=Customer')->assertForbidden();

        [$billing, , $membership] = $this->userWithRole('billing', $organization);
        $response = $this->actingAs($billing)->getJson('/office/invoices/customer-options?q=Direct')
            ->assertOk()
            ->assertJsonPath('customers.0.id', $customer->id);
        $this->assertStringNotContainsString('PRIVATE CUSTOMER NOTES', $response->getContent());

        $manage = Capability::query()->where('key', 'invoices.manage')->firstOrFail();
        $membership->capabilityOverrides()->attach($manage, ['effect' => 'deny']);
        $membership->unsetRelation('capabilityOverrides')->unsetRelation('roles');
        $this->actingAs($billing)->get('/office/invoices/create')->assertForbidden();

        $membership->capabilityOverrides()->detach($manage);
        $membership->update(['status' => 'inactive']);
        $this->actingAs($billing)->get('/office/invoices/create')->assertForbidden();
    }

    /** @return array{User, Organization, OrganizationMembership} */
    private function userWithRole(string $roleKey, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }

    /** @return array{Customer, Contact, ServiceLocation} */
    private function customerGraph(Organization $organization): array
    {
        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'display_name' => 'Direct Invoice Customer',
            'phone' => '(817) 555-0100',
            'phone_normalized' => '8175550100',
        ]);
        $contact = Contact::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Billing Contact',
            'is_preferred' => true,
        ]);
        $location = ServiceLocation::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Direct Customer Main Site',
            'primary_contact_id' => $contact->id,
        ]);

        return [$customer, $contact, $location];
    }
}
