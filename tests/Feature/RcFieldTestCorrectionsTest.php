<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RcFieldTestCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_office_ticket_navigation_targets_the_ticket_directory(): void
    {
        [$organization, $admin] = $this->admin();
        $this->ticket($organization, 'open', 'Navigation fixture');

        $response = $this->actingAs($admin)->get(route('office.home'));

        $response->assertOk()->assertSee('href="'.route('office.service-tickets.index').'"', false);
        $this->actingAs($admin)->get(route('office.service-tickets.index'))
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('Service Tickets')
            ->assertSee('Navigation fixture');
    }

    public function test_ticket_directory_filters_exact_statuses_and_preserves_organization_isolation(): void
    {
        [$organization, $admin] = $this->admin();
        [$otherOrganization] = $this->admin();
        $this->ticket($organization, 'open', 'Open organization ticket');
        $this->ticket($organization, 'on_hold', 'Held organization ticket');
        $this->ticket($organization, 'completed', 'Completed organization ticket');
        $this->ticket($otherOrganization, 'open', 'Foreign open ticket');

        $this->actingAs($admin)->get(route('office.service-tickets.index'))
            ->assertOk()
            ->assertSee('Open organization ticket')
            ->assertSee('Held organization ticket')
            ->assertSee('Completed organization ticket')
            ->assertDontSee('Foreign open ticket');

        $this->actingAs($admin)->get(route('office.service-tickets.index', ['status' => 'open']))
            ->assertOk()
            ->assertSee('Open organization ticket')
            ->assertDontSee('Held organization ticket')
            ->assertDontSee('Completed organization ticket')
            ->assertDontSee('Foreign open ticket');

        $this->actingAs($admin)->get(route('office.service-tickets.index', ['status' => 'on_hold']))
            ->assertOk()
            ->assertSee('Held organization ticket')
            ->assertDontSee('Open organization ticket')
            ->assertDontSee('Completed organization ticket');
    }

    public function test_filtered_and_unfiltered_empty_states_are_distinct(): void
    {
        [, $admin] = $this->admin();

        $this->actingAs($admin)->get(route('office.service-tickets.index'))
            ->assertOk()
            ->assertSee('No service tickets found.')
            ->assertDontSee('No service tickets match these filters.');

        $this->actingAs($admin)->get(route('office.service-tickets.index', ['status' => 'open']))
            ->assertOk()
            ->assertSee('No service tickets match these filters.')
            ->assertSee('Clear filters');
    }

    /** @return array{Organization, User} */
    private function admin(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'super_admin')->firstOrFail());

        return [$organization, $user];
    }

    private function ticket(Organization $organization, string $status, string $title): ServiceTicket
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
        ]);

        return ServiceTicket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => 'NDT-ST-2026-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => $title,
            'priority' => 'normal',
            'source' => 'internal',
            'purpose' => 'service_call',
            'billing_disposition' => 'billable',
            'status' => $status,
        ]);
    }
}
