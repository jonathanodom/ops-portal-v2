<?php

namespace Tests\Feature\Api;

use App\Models\Capability;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TicketEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_index_lists_tickets_for_the_organization_with_filters(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        $location = ServiceLocation::factory()->for($customer)->create(['organization_id' => $organization->id]);
        $open = $this->createTicket($organization, $customer, $location, ['status' => 'open', 'ticket_number' => 'NDT-ST-2026-0001']);
        $this->createTicket($organization, $customer, $location, ['status' => 'completed', 'ticket_number' => 'NDT-ST-2026-0002']);

        $response = $this->bearer($token)->getJson("/api/v1/tickets?customer_id={$customer->id}&status=open");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $open->id)
            ->assertJsonPath('data.0.location_id', (string) $location->id)
            ->assertJsonStructure(['data' => [['id', 'ticket_number', 'customer_id', 'location_id', 'contact_id', 'title', 'description', 'priority', 'source', 'purpose', 'billing_disposition', 'status', 'created_at', 'updated_at']]]);
    }

    public function test_index_does_not_return_tickets_from_another_organization(): void
    {
        [, , $token] = $this->jarvisIdentity();
        $other = Organization::factory()->create(['active' => true]);
        $customer = Customer::factory()->for($other)->create();
        $location = ServiceLocation::factory()->for($customer)->create(['organization_id' => $other->id]);
        $this->createTicket($other, $customer, $location);

        $response = $this->bearer($token)->getJson('/api/v1/tickets');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_index_rejects_an_invalid_status_filter(): void
    {
        [, , $token] = $this->jarvisIdentity();

        $response = $this->bearer($token)->getJson('/api/v1/tickets?status=not-a-real-status');

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_show_returns_the_ticket_summary(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        $location = ServiceLocation::factory()->for($customer)->create(['organization_id' => $organization->id]);
        $ticket = $this->createTicket($organization, $customer, $location);

        $response = $this->bearer($token)->getJson("/api/v1/tickets/{$ticket->id}");

        $response->assertOk()->assertJsonPath('data.id', (string) $ticket->id);
    }

    public function test_show_returns_not_found_for_a_ticket_in_another_organization_without_leaking_internals(): void
    {
        [, , $token] = $this->jarvisIdentity();
        $other = Organization::factory()->create(['active' => true]);
        $customer = Customer::factory()->for($other)->create();
        $location = ServiceLocation::factory()->for($customer)->create(['organization_id' => $other->id]);
        $ticket = $this->createTicket($other, $customer, $location);

        $response = $this->bearer($token)->getJson("/api/v1/tickets/{$ticket->id}");

        $response->assertStatus(404)
            ->assertJsonPath('error.message', 'The requested resource was not found.')
            ->assertDontSeeText('App\\Models\\ServiceTicket');
        $this->assertDatabaseHas('audit_events', ['event_type' => 'security.cross_organization_record_denied']);
    }

    public function test_store_requires_an_idempotency_key_header(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        [$customer, $location] = $this->customerAndLocation($organization);

        $response = $this->bearer($token)->postJson('/api/v1/tickets', $this->ticketPayload($customer, $location));

        $response->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
        $this->assertDatabaseCount('service_tickets', 0);
    }

    public function test_store_creates_a_ticket_and_records_an_audit_event(): void
    {
        [$user, $organization, $token] = $this->jarvisIdentity();
        [$customer, $location] = $this->customerAndLocation($organization);

        $response = $this->bearer($token)
            ->withHeader('Idempotency-Key', 'idem-key-1')
            ->postJson('/api/v1/tickets', $this->ticketPayload($customer, $location));

        $response->assertStatus(201)
            ->assertJsonPath('data.customer_id', (string) $customer->id)
            ->assertJsonPath('data.location_id', (string) $location->id)
            ->assertJsonPath('data.source', 'jarvis')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.purpose', 'service_call')
            ->assertJsonPath('data.billing_disposition', 'billable');

        $this->assertDatabaseCount('service_tickets', 1);
        $ticket = ServiceTicket::query()->firstOrFail();
        $this->assertSame($user->id, $ticket->created_by_id);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'service_ticket.created',
            'subject_id' => $ticket->id,
            'actor_id' => $user->id,
        ]);
    }

    public function test_replaying_the_same_idempotency_key_does_not_create_a_second_ticket(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        [$customer, $location] = $this->customerAndLocation($organization);
        $payload = $this->ticketPayload($customer, $location);

        $first = $this->bearer($token)->withHeader('Idempotency-Key', 'replay-key')->postJson('/api/v1/tickets', $payload);
        $second = $this->bearer($token)->withHeader('Idempotency-Key', 'replay-key')->postJson('/api/v1/tickets', $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('service_tickets', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_a_validation_failure_does_not_consume_the_idempotency_key(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        [$customer, $location] = $this->customerAndLocation($organization);
        $invalid = $this->ticketPayload($customer, $location);
        unset($invalid['title']);

        $failed = $this->bearer($token)->withHeader('Idempotency-Key', 'retry-key')->postJson('/api/v1/tickets', $invalid);
        $failed->assertStatus(422);
        $this->assertDatabaseCount('idempotency_keys', 0);

        $valid = $this->ticketPayload($customer, $location);
        $succeeded = $this->bearer($token)->withHeader('Idempotency-Key', 'retry-key')->postJson('/api/v1/tickets', $valid);
        $succeeded->assertStatus(201);
        $this->assertDatabaseCount('service_tickets', 1);
    }

    public function test_store_rejects_a_location_that_does_not_belong_to_the_customer(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        [$customer] = $this->customerAndLocation($organization);
        $otherCustomer = Customer::factory()->for($organization)->create();
        $mismatchedLocation = ServiceLocation::factory()->for($otherCustomer)->create(['organization_id' => $organization->id]);

        $payload = $this->ticketPayload($customer, $mismatchedLocation);
        $response = $this->bearer($token)->withHeader('Idempotency-Key', 'mismatch-key')->postJson('/api/v1/tickets', $payload);

        $response->assertStatus(422)->assertJsonPath('error.details.service_location_id.0', 'The location must belong to the selected customer.');
    }

    public function test_missing_token_ability_is_rejected_with_403(): void
    {
        [, $organization, , $membership] = $this->jarvisIdentity(['tickets.read']);
        [$customer, $location] = $this->customerAndLocation($organization);
        $token = $membership->user->createToken('read-only', ['tickets.read'])->plainTextToken;

        $response = $this->bearer($token)->withHeader('Idempotency-Key', 'k')->postJson('/api/v1/tickets', $this->ticketPayload($customer, $location));

        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_update_sets_priority_and_appends_description_and_records_audit(): void
    {
        [$user, $organization, $token] = $this->jarvisIdentity();
        [$customer, $location] = $this->customerAndLocation($organization);
        $ticket = $this->createTicket($organization, $customer, $location, ['description' => 'Original report.']);

        $response = $this->bearer($token)->withHeader('Idempotency-Key', 'update-key-1')->patchJson("/api/v1/tickets/{$ticket->id}", [
            'priority' => 'high',
            'description_append' => 'Caller called back with more detail.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.description', "Original report.\n\nCaller called back with more detail.");
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'service_ticket.updated',
            'subject_id' => $ticket->id,
            'actor_id' => $user->id,
        ]);
    }

    public function test_update_requires_an_idempotency_key_header(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        [$customer, $location] = $this->customerAndLocation($organization);
        $ticket = $this->createTicket($organization, $customer, $location);

        $response = $this->bearer($token)->patchJson("/api/v1/tickets/{$ticket->id}", ['priority' => 'high']);

        $response->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
        $this->assertSame('normal', $ticket->fresh()->priority);
    }

    public function test_update_requires_at_least_one_field(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        [$customer, $location] = $this->customerAndLocation($organization);
        $ticket = $this->createTicket($organization, $customer, $location);

        $response = $this->bearer($token)->withHeader('Idempotency-Key', 'k2')->patchJson("/api/v1/tickets/{$ticket->id}", []);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_replaying_the_same_update_idempotency_key_does_not_double_append(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        [$customer, $location] = $this->customerAndLocation($organization);
        $ticket = $this->createTicket($organization, $customer, $location, ['description' => 'Base.']);
        $body = ['description_append' => 'Appended once.'];

        $first = $this->bearer($token)->withHeader('Idempotency-Key', 'update-replay')->patchJson("/api/v1/tickets/{$ticket->id}", $body);
        $second = $this->bearer($token)->withHeader('Idempotency-Key', 'update-replay')->patchJson("/api/v1/tickets/{$ticket->id}", $body);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame("Base.\n\nAppended once.", $ticket->fresh()->description);
    }

    public function test_the_same_idempotency_key_cannot_be_replayed_against_a_different_ticket(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        [$customer, $location] = $this->customerAndLocation($organization);
        $ticketA = $this->createTicket($organization, $customer, $location);
        $ticketB = $this->createTicket($organization, $customer, $location);

        $this->bearer($token)->withHeader('Idempotency-Key', 'shared-key')->patchJson("/api/v1/tickets/{$ticketA->id}", ['priority' => 'high'])->assertOk();
        $second = $this->bearer($token)->withHeader('Idempotency-Key', 'shared-key')->patchJson("/api/v1/tickets/{$ticketB->id}", ['priority' => 'urgent']);

        $second->assertOk()->assertJsonPath('data.id', (string) $ticketB->id)->assertJsonPath('data.priority', 'urgent');
        $this->assertSame('high', $ticketA->fresh()->priority);
        $this->assertSame('urgent', $ticketB->fresh()->priority);
    }

    public function test_update_returns_not_found_for_a_ticket_in_another_organization(): void
    {
        [, , $token] = $this->jarvisIdentity();
        $other = Organization::factory()->create(['active' => true]);
        $customer = Customer::factory()->for($other)->create();
        $location = ServiceLocation::factory()->for($customer)->create(['organization_id' => $other->id]);
        $ticket = $this->createTicket($other, $customer, $location);

        $response = $this->bearer($token)->withHeader('Idempotency-Key', 'k3')->patchJson("/api/v1/tickets/{$ticket->id}", ['priority' => 'high']);

        $response->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    public function test_update_missing_token_ability_is_rejected_with_403(): void
    {
        [, $organization, $token] = $this->jarvisIdentity(['tickets.read', 'tickets.create']);
        [$customer, $location] = $this->customerAndLocation($organization);
        $ticket = $this->createTicket($organization, $customer, $location);

        $response = $this->bearer($token)->withHeader('Idempotency-Key', 'k4')->patchJson("/api/v1/tickets/{$ticket->id}", ['priority' => 'high']);

        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_revoked_membership_capability_is_rejected_with_403(): void
    {
        [, , $token, $membership] = $this->jarvisIdentity();
        $capability = Capability::query()->where('key', 'api.tickets.read')->firstOrFail();
        $membership->capabilityOverrides()->attach($capability, ['effect' => 'deny']);

        $response = $this->bearer($token)->getJson('/api/v1/tickets');

        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    /** @param array<string, mixed> $overrides */
    private function createTicket(Organization $organization, Customer $customer, ServiceLocation $location, array $overrides = []): ServiceTicket
    {
        return ServiceTicket::query()->create(array_merge([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => 'NDT-ST-2026-'.str_pad((string) (ServiceTicket::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'Test ticket',
            'priority' => 'normal',
            'source' => 'internal',
            'purpose' => 'service_call',
            'billing_disposition' => 'billable',
            'status' => 'open',
        ], $overrides));
    }

    /** @return array{Customer, ServiceLocation} */
    private function customerAndLocation(Organization $organization): array
    {
        $customer = Customer::factory()->for($organization)->create();
        $location = ServiceLocation::factory()->for($customer)->create(['organization_id' => $organization->id]);

        return [$customer, $location];
    }

    /** @return array<string, mixed> */
    private function ticketPayload(Customer $customer, ServiceLocation $location): array
    {
        return [
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'title' => 'Front office phones rebooting',
            'description' => 'Customer reports intermittent phone reboots.',
            'priority' => 'normal',
            'source' => 'jarvis',
        ];
    }

    /**
     * @param  array<int, string>|null  $abilities
     * @return array{User, Organization, string, OrganizationMembership}
     */
    private function jarvisIdentity(?array $abilities = null): array
    {
        $organization = Organization::factory()->create(['active' => true]);
        $user = User::query()->create([
            'name' => 'JARVIS Core',
            'email' => 'jarvis-core+'.uniqid().'@service.newdaytech.net',
            'password' => Hash::make(str()->random(64)),
            'status' => 'service_account',
        ]);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'jarvis_service')->firstOrFail());

        $abilities ??= ['customers.read', 'contacts.read', 'locations.read', 'projects.read', 'tickets.read', 'tickets.create', 'tickets.update', 'communications.create'];
        $token = $user->createToken('jarvis-core', $abilities)->plainTextToken;

        return [$user, $organization, $token, $membership];
    }

    private function bearer(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
