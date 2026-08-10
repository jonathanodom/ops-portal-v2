<?php

namespace Tests\Feature;

use App\Domain\CustomerCreationWorkflow;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\User;
use App\Support\AuditRecorder;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class QuickTicketCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_ticket_customer_search_matches_operational_fields_and_returns_safe_active_projection(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        [$customer, $contact, $location] = $this->customerGraph($organization);
        $customer->update(['legal_name' => 'Acme Legal Holdings', 'notes' => 'PRIVATE CUSTOMER NOTE']);
        $location->update(['access_instructions' => 'PRIVATE ACCESS', 'site_notes' => 'PRIVATE SITE NOTE']);
        Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => 'Archived Acme', 'status' => 'inactive']);
        $foreign = Organization::factory()->create();
        $foreignCustomer = Customer::factory()->create(['organization_id' => $foreign->id, 'display_name' => 'Foreign Acme']);

        foreach (['Acme Legal', '8175550100', $contact->email, $location->name, '100 Main'] as $term) {
            $this->actingAs($dispatcher)->getJson('/office/service-tickets/customer-options?q='.urlencode($term))
                ->assertOk()
                ->assertJsonPath('customers.0.id', $customer->id);
        }

        $response = $this->actingAs($dispatcher)->getJson('/office/service-tickets/customer-options?q=Acme')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonMissing(['id' => $foreignCustomer->id]);
        $json = $response->getContent();
        $this->assertStringNotContainsString('PRIVATE CUSTOMER NOTE', $json);
        $this->assertStringNotContainsString('PRIVATE ACCESS', $json);
        $this->assertStringNotContainsString('PRIVATE SITE NOTE', $json);
    }

    public function test_quick_add_creates_active_customer_preferred_contact_and_primary_location_atomically(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');

        $response = $this->actingAs($dispatcher)->postJson('/office/service-tickets/quick-customers', $this->payload() + [
            'organization_id' => Organization::factory()->create()->id,
        ])->assertCreated()->assertJsonPath('customer.display_name', 'Quick Add Customer');

        $customer = Customer::query()->firstOrFail();
        $contact = $customer->contacts()->firstOrFail();
        $location = $customer->serviceLocations()->firstOrFail();
        $this->assertSame($organization->id, $customer->organization_id);
        $this->assertSame('active', $customer->status);
        $this->assertTrue($contact->is_preferred);
        $this->assertTrue($location->is_primary);
        $this->assertSame($contact->id, $location->primary_contact_id);
        $this->assertSame($organization->timezone, $location->timezone);
        $response->assertJsonPath('customer.locations.0.id', $location->id)
            ->assertJsonPath('customer.contacts.0.id', $contact->id);
        $this->assertDatabaseCount('audit_events', 3);
    }

    public function test_quick_add_validation_failure_creates_nothing_and_returns_field_errors(): void
    {
        [$dispatcher] = $this->userWithRole('dispatcher');
        $payload = $this->payload();
        $payload['location']['postal_code'] = 'bad';

        $this->actingAs($dispatcher)->postJson('/office/service-tickets/quick-customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location.postal_code');

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('service_locations', 0);
    }

    public function test_shared_creation_workflow_rolls_back_when_persistence_side_effect_fails(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        $audit = $this->mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('simulated audit failure'));

        try {
            app(CustomerCreationWorkflow::class)->create($organization, $dispatcher, $this->payload(), $audit);
            $this->fail('The simulated failure should escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated audit failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('service_locations', 0);
    }

    public function test_quick_add_requires_customer_management_but_search_remains_available(): void
    {
        [$dispatcher, $organization, $membership] = $this->userWithRole('dispatcher');
        $manage = Capability::query()->where('key', 'customers.manage')->firstOrFail();
        $membership->capabilityOverrides()->attach($manage, ['effect' => 'deny']);
        $membership->unsetRelation('capabilityOverrides')->unsetRelation('roles');

        $this->actingAs($dispatcher)->getJson('/office/service-tickets/customer-options?q=none')->assertOk();
        $this->actingAs($dispatcher)->get('/office/service-tickets/create')
            ->assertOk()->assertDontSee('Add customer and location');
        $this->actingAs($dispatcher)->postJson('/office/service-tickets/quick-customers', $this->payload())->assertForbidden();

        $membership->update(['status' => 'inactive']);
        $this->actingAs($dispatcher)->getJson('/office/service-tickets/customer-options?q=none')->assertForbidden();
    }

    public function test_search_is_limited_and_ticket_validation_restores_selected_customer_data(): void
    {
        [$dispatcher, $organization] = $this->userWithRole('dispatcher');
        foreach (range(1, 12) as $index) {
            $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => "Search Match {$index}"]);
            ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        }
        [$selected, , $location] = $this->customerGraph($organization);

        $this->actingAs($dispatcher)->getJson('/office/service-tickets/customer-options?q=Search%20Match')
            ->assertOk()->assertJsonCount(10, 'customers');

        $this->actingAs($dispatcher)->post('/office/service-tickets', [
            'customer_id' => $selected->id,
            'service_location_id' => $location->id,
            'title' => '',
            'priority' => 'normal',
            'source' => 'phone',
        ])->assertSessionHasErrors('title');
        $this->actingAs($dispatcher)->get('/office/service-tickets/create')
            ->assertOk()
            ->assertSee($selected->display_name)
            ->assertSee('value="'.$selected->id.'"', false)
            ->assertSee($location->name);
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
            'display_name' => 'Acme Service Customer',
            'phone' => '(817) 555-0100',
            'phone_normalized' => '8175550100',
        ]);
        $contact = Contact::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Pat Lee',
            'phone' => '817-555-0100',
            'phone_normalized' => '8175550100',
            'email' => 'pat@example.test',
            'is_preferred' => true,
            'active' => true,
        ]);
        $location = ServiceLocation::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'primary_contact_id' => $contact->id,
            'name' => 'Jacksboro Office',
            'address_line_1' => '100 Main Street',
            'city' => 'Jacksboro',
            'state' => 'TX',
            'postal_code' => '76458',
            'timezone' => 'America/Chicago',
            'is_primary' => true,
            'active' => true,
        ]);

        return [$customer, $contact, $location];
    }

    private function payload(): array
    {
        return [
            'type' => 'business',
            'display_name' => 'Quick Add Customer',
            'phone' => '(817) 555-0199',
            'email' => 'quick@example.test',
            'contact' => [
                'name' => 'Jordan Customer',
                'role' => 'Manager',
                'phone' => '817-555-0188',
                'email' => 'jordan@example.test',
            ],
            'location' => [
                'name' => 'Main location',
                'address_line_1' => '900 Main Street',
                'city' => 'Jacksboro',
                'state' => 'TX',
                'postal_code' => '76458',
                'timezone' => 'America/Chicago',
                'access_instructions' => 'Call on arrival',
            ],
        ];
    }
}
