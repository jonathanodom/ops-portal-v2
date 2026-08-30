<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JarvisServiceAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_command_creates_an_expiring_organization_scoped_service_identity(): void
    {
        config()->set('jarvis.service_token_ttl_days', 90);
        $organization = Organization::factory()->create(['active' => true]);

        $this->artisan('jarvis:create-service-account', [
            '--organization' => (string) $organization->id,
            '--email' => 'jarvis-command@service.newdaytech.net',
        ])->expectsOutputToContain('Expires:')->assertSuccessful();

        $user = User::query()->where('email', 'jarvis-command@service.newdaytech.net')->firstOrFail();
        $this->assertSame('service_account', $user->status);
        $this->assertCount(1, $user->tokens);
        $this->assertTrue($user->tokens->first()->expires_at->between(now()->addDays(89), now()->addDays(91)));
        $this->assertSame([
            'customers.read', 'contacts.read', 'locations.read', 'projects.read',
            'tickets.read', 'tickets.create', 'tickets.update', 'communications.create',
        ], $user->tokens->first()->abilities);
        $this->assertTrue($user->memberships()->where('organization_id', $organization->id)->firstOrFail()->roles()->where('key', 'jarvis_service')->exists());

        $membership = $user->memberships()->where('organization_id', $organization->id)->firstOrFail();
        $membership->roles()->attach(Role::query()->where('key', 'super_admin')->value('id'));
        $this->artisan('jarvis:create-service-account', [
            '--organization' => (string) $organization->id,
            '--email' => $user->email,
        ])->assertSuccessful();
        $this->assertSame(['jarvis_service'], $membership->roles()->pluck('key')->all());

        $user->update(['password' => Hash::make('Known-Service-Password-123')]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Known-Service-Password-123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_rotation_revokes_the_prior_token_and_issues_one_replacement(): void
    {
        $organization = Organization::factory()->create(['active' => true]);
        $arguments = ['--organization' => (string) $organization->id, '--email' => 'jarvis-rotate@service.newdaytech.net'];
        $this->artisan('jarvis:create-service-account', $arguments)->assertSuccessful();
        $user = User::query()->where('email', $arguments['--email'])->firstOrFail();
        $originalTokenId = $user->tokens()->sole()->id;

        $this->artisan('jarvis:create-service-account', [...$arguments, '--rotate' => true])->assertSuccessful();

        $this->assertCount(1, $user->tokens()->get());
        $this->assertNotSame($originalTokenId, $user->tokens()->sole()->id);
    }

    public function test_command_refuses_human_accounts_inactive_organizations_and_cross_organization_reuse(): void
    {
        $organization = Organization::factory()->create(['active' => true]);
        $other = Organization::factory()->create(['active' => true]);
        $inactive = Organization::factory()->create(['active' => false]);
        $human = User::factory()->create(['email' => 'human@example.test', 'status' => 'active']);

        $this->artisan('jarvis:create-service-account', ['--organization' => (string) $organization->id, '--email' => $human->email])
            ->expectsOutputToContain('human account')
            ->assertFailed();
        $this->assertSame('active', $human->fresh()->status);

        $this->artisan('jarvis:create-service-account', ['--organization' => (string) $inactive->id, '--email' => 'inactive-org@service.example.test'])
            ->assertFailed();

        $service = User::factory()->create(['email' => 'scoped@service.example.test', 'status' => 'service_account']);
        OrganizationMembership::factory()->create(['organization_id' => $other->id, 'user_id' => $service->id, 'status' => 'active']);
        $this->artisan('jarvis:create-service-account', ['--organization' => (string) $organization->id, '--email' => $service->email])
            ->expectsOutputToContain('another organization')
            ->assertFailed();
    }
}
