<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerLocationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_manager_creates_customer_contact_and_first_location_atomically(): void
    {
        [$user, $organization] = $this->userWithRole('dispatcher');

        $response = $this->actingAs($user)->post('/office/customers', $this->customerPayload());

        $customer = Customer::query()->firstOrFail();
        $response->assertRedirect(route('office.customers.show', $customer));
        $this->assertSame($organization->id, $customer->organization_id);
        $this->assertSame('8175550100', $customer->phone_normalized);
        $this->assertDatabaseHas('contacts', ['customer_id' => $customer->id, 'name' => 'Pat Lee', 'is_preferred' => true]);
        $this->assertDatabaseHas('service_locations', ['customer_id' => $customer->id, 'is_primary' => true, 'active' => true]);
        $this->assertDatabaseCount('audit_events', 3);
        $this->assertNull(AuditEvent::query()->where('event_type', 'customer.created')->firstOrFail()->metadata['phone'] ?? null);
    }

    public function test_invalid_first_location_rolls_back_the_entire_customer_creation(): void
    {
        [$user] = $this->userWithRole('dispatcher');
        $payload = $this->customerPayload();
        $payload['location']['postal_code'] = 'invalid';

        $this->actingAs($user)->post('/office/customers', $payload)->assertSessionHasErrors('location.postal_code');

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('service_locations', 0);
    }

    public function test_preferred_contact_and_primary_location_are_single_per_customer(): void
    {
        [$user, $organization] = $this->userWithRole('dispatcher');
        [$customer, $firstContact, $firstLocation] = $this->customerGraph($organization);

        $this->actingAs($user)->post("/office/customers/{$customer->id}/contacts", [
            'name' => 'Morgan Diaz', 'phone' => '817-555-0200', 'is_preferred' => '1', 'active' => '1',
        ])->assertRedirect();
        $this->assertFalse($firstContact->fresh()->is_preferred);
        $secondContact = Contact::query()->where('name', 'Morgan Diaz')->firstOrFail();

        $this->actingAs($user)->post("/office/customers/{$customer->id}/locations", [
            'name' => 'Warehouse', 'primary_contact_id' => $secondContact->id,
            'address_line_1' => '200 Industrial Way', 'city' => 'Fort Worth', 'state' => 'TX',
            'postal_code' => '76102', 'timezone' => 'America/Chicago', 'is_primary' => '1', 'active' => '1',
        ])->assertRedirect();
        $this->assertFalse($firstLocation->fresh()->is_primary);
        $this->assertSame(1, $customer->serviceLocations()->where('is_primary', true)->count());
    }

    public function test_location_rejects_contact_from_another_customer(): void
    {
        [$user, $organization] = $this->userWithRole('dispatcher');
        [$customer] = $this->customerGraph($organization);
        [$otherCustomer, $otherContact] = $this->customerGraph($organization);

        $this->actingAs($user)->post("/office/customers/{$customer->id}/locations", [
            'name' => 'Invalid', 'primary_contact_id' => $otherContact->id,
            'address_line_1' => '300 Main St', 'city' => 'Fort Worth', 'state' => 'TX',
            'postal_code' => '76103', 'timezone' => 'America/Chicago', 'active' => '1',
        ])->assertSessionHasErrors('primary_contact_id');

        $this->assertSame(1, $otherCustomer->contacts()->count());
        $this->assertDatabaseMissing('service_locations', ['customer_id' => $customer->id, 'name' => 'Invalid']);
    }

    public function test_role_matrix_and_capability_override_protect_writes(): void
    {
        [$technician, $organization] = $this->userWithRole('technician');
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        [$billing] = $this->userWithRole('billing', $organization);
        [$customer] = $this->customerGraph($organization);

        $this->actingAs($technician)->get('/field/customers')->assertOk();
        $this->actingAs($technician)->get('/office/customers')->assertForbidden();
        $this->actingAs($reviewer)->get('/office/customers')->assertOk();
        $this->actingAs($billing)->get("/office/customers/{$customer->id}")->assertOk();
        $this->actingAs($reviewer)->get('/office/customers/create')->assertForbidden();

        $membership = $reviewer->memberships()->where('organization_id', $organization->id)->firstOrFail();
        $view = Capability::query()->where('key', 'customers.view')->firstOrFail();
        $membership->capabilityOverrides()->attach($view, ['effect' => 'deny']);
        $this->actingAs($reviewer)->get('/office/customers')->assertForbidden();
    }

    public function test_cross_organization_urls_and_forged_ids_cannot_change_scope(): void
    {
        [$user, $organization] = $this->userWithRole('super_admin');
        $other = Organization::factory()->create();
        [$foreignCustomer, $foreignContact, $foreignLocation] = $this->customerGraph($other);

        $this->actingAs($user)->get("/office/customers/{$foreignCustomer->id}?organization_id={$other->id}")->assertNotFound();
        $this->actingAs($user)->get("/office/locations/{$foreignLocation->id}")->assertNotFound();
        $this->actingAs($user)->get('/office/customers?search='.$foreignContact->email)
            ->assertOk()->assertDontSee($foreignCustomer->display_name);
        $this->assertSame($organization->id, $user->memberships()->firstOrFail()->organization_id);
        $this->assertSame(2, AuditEvent::query()
            ->where('organization_id', $organization->id)
            ->where('event_type', 'security.cross_organization_record_denied')
            ->count());
    }

    public function test_directory_search_matches_names_contact_data_phone_and_address(): void
    {
        [$user, $organization] = $this->userWithRole('reviewer');
        [$customer] = $this->customerGraph($organization);

        foreach (['Acme', 'pat@example.test', '8175550100', 'Jacksboro', '76458'] as $term) {
            $this->actingAs($user)->get('/office/customers?search='.urlencode($term))
                ->assertOk()->assertSee($customer->display_name);
        }
    }

    public function test_customer_workspace_uses_wide_indexes_and_shared_navigation(): void
    {
        [$manager, $organization] = $this->userWithRole('dispatcher');
        [$customer, , $location] = $this->customerGraph($organization);

        $customers = $this->actingAs($manager)->get('/office/customers?search=Acme&status=active&type=business');
        $customers->assertOk()
            ->assertSee('data-office-width="workspace"', false)
            ->assertSee('data-office-header-width="workspace"', false)
            ->assertSee('aria-label="Customer workspace"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('data-office-table', false)
            ->assertSee('data-office-mobile-list', false)
            ->assertSee($customer->display_name)
            ->assertSee('Clear');

        $locations = $this->actingAs($manager)->get('/office/locations?search=Jacksboro&status=active');
        $locations->assertOk()
            ->assertSee('data-office-width="workspace"', false)
            ->assertSee('data-office-header-width="workspace"', false)
            ->assertSee('data-office-primary-customers', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('aria-label="Customer workspace"', false)
            ->assertSee($location->name)
            ->assertSee('Clear')
            ->assertDontSee('>Service Locations</a>', false);
    }

    public function test_read_only_customer_workspace_does_not_show_create_action(): void
    {
        [$reviewer] = $this->userWithRole('reviewer');

        $this->actingAs($reviewer)->get('/office/customers')
            ->assertOk()
            ->assertDontSee('Add customer');
    }

    public function test_field_directory_is_active_only_and_excludes_private_office_content(): void
    {
        [$user, $organization] = $this->userWithRole('technician');
        [$customer, $contact, $location] = $this->customerGraph($organization);
        $inactive = Customer::factory()->create([
            'organization_id' => $organization->id, 'display_name' => 'Archived Secret Customer', 'status' => 'inactive',
        ]);
        $location->update([
            'access_instructions' => 'Use north gate code supplied on arrival.',
            'site_notes' => 'OFFICE ONLY MARGIN NOTE',
        ]);
        $customer->update(['notes' => 'OFFICE ONLY CUSTOMER NOTE']);

        $this->actingAs($user)->get('/field/customers')
            ->assertOk()->assertSee($customer->display_name)->assertDontSee($inactive->display_name);
        $this->actingAs($user)->get("/field/locations/{$location->id}")
            ->assertOk()
            ->assertSee('Use north gate code supplied on arrival.')
            ->assertSee($contact->name)
            ->assertDontSee('OFFICE ONLY MARGIN NOTE')
            ->assertDontSee('OFFICE ONLY CUSTOMER NOTE');
        $this->actingAs($user)->get("/field/customers/{$inactive->id}")->assertNotFound();
    }

    public function test_archiving_preserves_records_and_removes_them_from_field_views(): void
    {
        [$manager, $organization] = $this->userWithRole('dispatcher');
        [$technician] = $this->userWithRole('technician', $organization);
        [$customer, $contact, $location] = $this->customerGraph($organization);

        $this->actingAs($manager)->put("/office/customers/{$customer->id}", [
            'type' => $customer->type, 'display_name' => $customer->display_name,
            'status' => 'inactive',
        ])->assertRedirect();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'inactive']);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
        $this->assertDatabaseHas('service_locations', ['id' => $location->id]);
        $this->actingAs($technician)->get("/field/customers/{$customer->id}")->assertNotFound();
    }

    public function test_contacts_and_locations_can_be_deactivated_and_reactivated_without_deletion(): void
    {
        [$manager, $organization] = $this->userWithRole('dispatcher');
        [$customer, $contact, $location] = $this->customerGraph($organization);

        $this->actingAs($manager)->put("/office/customers/{$customer->id}/contacts/{$contact->id}", [
            'name' => $contact->name, 'phone' => $contact->phone, 'email' => $contact->email,
            'is_preferred' => '0', 'active' => '0',
        ])->assertRedirect();
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'active' => false, 'is_preferred' => false]);
        $this->assertNull($location->fresh()->primary_contact_id);

        $this->actingAs($manager)->put("/office/customers/{$customer->id}/contacts/{$contact->id}", [
            'name' => $contact->name, 'phone' => $contact->phone, 'email' => $contact->email,
            'is_preferred' => '1', 'active' => '1',
        ])->assertRedirect();
        $this->assertTrue($contact->fresh()->active);
        $this->assertTrue($contact->fresh()->is_preferred);

        $this->actingAs($manager)->put("/office/locations/{$location->id}", [
            'name' => $location->name, 'address_line_1' => $location->address_line_1,
            'city' => $location->city, 'state' => $location->state, 'postal_code' => $location->postal_code,
            'timezone' => $location->timezone, 'is_primary' => '0', 'active' => '0',
        ])->assertRedirect();
        $this->assertDatabaseHas('service_locations', ['id' => $location->id, 'active' => false, 'is_primary' => false]);

        $this->actingAs($manager)->put("/office/locations/{$location->id}", [
            'name' => $location->name, 'address_line_1' => $location->address_line_1,
            'city' => $location->city, 'state' => $location->state, 'postal_code' => $location->postal_code,
            'timezone' => $location->timezone, 'is_primary' => '1', 'active' => '1',
        ])->assertRedirect();
        $this->assertTrue($location->fresh()->active);
        $this->assertTrue($location->fresh()->is_primary);
    }

    /** @return array{User, Organization, OrganizationMembership} */
    private function userWithRole(string $roleKey, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }

    /** @return array{Customer, Contact, ServiceLocation} */
    private function customerGraph(Organization $organization): array
    {
        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'display_name' => 'Acme Service Customer',
            'phone' => '(817) 555-0100',
            'phone_normalized' => '8175550100',
        ]);
        $contact = Contact::query()->create([
            'organization_id' => $organization->id, 'customer_id' => $customer->id,
            'name' => 'Pat Lee', 'phone' => '817-555-0100', 'phone_normalized' => '8175550100',
            'email' => 'pat@example.test', 'is_preferred' => true, 'active' => true,
        ]);
        $location = ServiceLocation::query()->create([
            'organization_id' => $organization->id, 'customer_id' => $customer->id,
            'primary_contact_id' => $contact->id, 'name' => 'Jacksboro Office',
            'address_line_1' => '100 Main Street', 'city' => 'Jacksboro', 'state' => 'TX',
            'postal_code' => '76458', 'timezone' => 'America/Chicago',
            'is_primary' => true, 'active' => true,
        ]);

        return [$customer, $contact, $location];
    }

    private function customerPayload(): array
    {
        return [
            'type' => 'business', 'display_name' => 'NewDay Test Customer', 'status' => 'active',
            'organization_id' => 999999,
            'phone' => '(817) 555-0100', 'email' => 'office@example.test', 'notes' => 'Office context',
            'contact' => ['name' => 'Pat Lee', 'role' => 'Manager', 'phone' => '817-555-0101', 'email' => 'pat@example.test'],
            'location' => [
                'name' => 'Main office', 'address_line_1' => '100 Main Street', 'city' => 'Jacksboro',
                'state' => 'TX', 'postal_code' => '76458', 'timezone' => 'America/Chicago',
                'access_instructions' => 'Call on arrival', 'site_notes' => 'Office-only note',
            ],
        ];
    }
}
