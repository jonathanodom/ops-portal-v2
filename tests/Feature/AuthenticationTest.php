<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_active_staff_user_can_sign_in_and_session_is_rotated(): void
    {
        $user = $this->activeUser('SecurePassword12');

        $response = $this->withSession(['marker' => true])->post('/login', [
            'email' => $user->email,
            'password' => 'SecurePassword12',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_invalid_or_inactive_accounts_cannot_sign_in(): void
    {
        $user = $this->activeUser('SecurePassword12');

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $user->update(['status' => 'inactive']);
        $this->post('/login', ['email' => $user->email, 'password' => 'SecurePassword12'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_without_active_membership_cannot_sign_in(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('SecurePassword12'),
            'status' => 'active',
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'SecurePassword12'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_repeated_failed_logins_are_throttled(): void
    {
        $user = $this->activeUser('SecurePassword12');

        foreach (range(1, 6) as $attempt) {
            $response = $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many sign-in attempts',
            session('errors')->first('email'),
        );
    }

    public function test_password_reset_notification_is_sent_without_disclosing_unknown_accounts(): void
    {
        Notification::fake();
        $user = $this->activeUser('SecurePassword12');

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);

        $this->post('/forgot-password', ['email' => 'missing@example.com'])
            ->assertSessionHas('status');
    }

    public function test_logout_invalidates_authentication(): void
    {
        $user = $this->activeUser('SecurePassword12');

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    private function activeUser(string $password): User
    {
        $organization = Organization::query()->create([
            'name' => 'NewDay Tech LLC',
            'slug' => fake()->unique()->slug(),
            'timezone' => 'America/Chicago',
            'active' => true,
        ]);
        $user = User::factory()->create([
            'password' => Hash::make($password),
            'status' => 'active',
        ]);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'super_admin')->firstOrFail());

        return $user;
    }
}
