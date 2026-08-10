<?php

namespace Tests\Feature;

use App\Jobs\DeleteUnusedOrganizationBrandAsset;
use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class OrganizationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_settings_tabs_follow_capabilities_and_legacy_billing_route_redirects(): void
    {
        [$admin, $organization, $adminMembership] = $this->userWithRole('super_admin');
        [$billing, , $billingMembership] = $this->userWithRole('billing', $organization);
        [$reviewer, , $reviewerMembership] = $this->userWithRole('reviewer', $organization);

        $this->actingAs($admin)->get('/office/settings')->assertRedirect('/office/settings/organization');
        $this->actingAs($admin)->get('/office/settings/organization')->assertOk()->assertSee('Organization')->assertSee('Billing')->assertSee('Invoices');
        $billingMembership->capabilityOverrides()->attach(Capability::query()->where('key', 'billing.settings.manage')->firstOrFail(), ['effect' => 'grant']);
        $this->actingAs($billing)->get('/office/settings')->assertRedirect('/office/settings/billing');
        $this->actingAs($billing)->get('/office/settings/billing')->assertOk()->assertDontSee('href="'.route('office.settings.organization.edit').'"', false);
        $this->actingAs($billing)->get('/office/billing/settings')->assertRedirect('/office/settings/billing');
        $this->actingAs($reviewer)->get('/office/settings')->assertForbidden();

        $capability = Capability::query()->where('key', 'organization.settings.manage')->firstOrFail();
        $reviewerMembership->capabilityOverrides()->attach($capability, ['effect' => 'grant']);
        $this->actingAs($reviewer)->get('/office/settings/organization')->assertOk();
        $adminMembership->capabilityOverrides()->attach($capability, ['effect' => 'deny']);
        $this->actingAs($admin)->get('/office/settings/organization')->assertForbidden();
        $adminMembership->update(['status' => 'inactive']);
        $this->actingAs($admin)->get('/office/settings')->assertForbidden();
    }

    public function test_profile_is_partial_timezone_change_requires_confirmation_and_audit_is_safe(): void
    {
        [$admin, $organization] = $this->userWithRole('super_admin');
        $originalUpdatedAt = $organization->updated_at;
        $payload = [
            'name' => 'Canonical NewDay', 'legal_name' => '', 'email' => '', 'phone' => '',
            'website' => '', 'address_line_1' => '', 'address_line_2' => '', 'city' => '',
            'state' => '', 'postal_code' => '', 'timezone' => 'America/New_York',
        ];

        $this->actingAs($admin)->put('/office/settings/organization', $payload)
            ->assertSessionHasErrors('confirm_timezone_change');
        $this->assertSame('America/Chicago', $organization->fresh()->timezone);

        $this->travel(1)->seconds();
        $this->actingAs($admin)->put('/office/settings/organization', $payload + ['confirm_timezone_change' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $organization->refresh();
        $this->assertSame('Canonical NewDay', $organization->name);
        $this->assertSame('America/New_York', $organization->timezone);
        $this->assertNull($organization->email);
        $this->assertNotEquals($originalUpdatedAt, $organization->updated_at);

        $audit = AuditEvent::query()->where('event_type', 'organization.settings_updated')->latest('occurred_at')->firstOrFail();
        $this->assertContains('timezone', $audit->metadata['changed_fields']);
        $this->assertStringNotContainsString('Canonical NewDay', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_logo_upload_is_private_opaque_scoped_and_reset_queues_cleanup(): void
    {
        Storage::fake('branding-test');
        Queue::fake();
        config()->set('organization.branding_disk', 'branding-test');
        [$admin, $organization] = $this->userWithRole('super_admin');

        $this->actingAs($admin)->post('/office/settings/organization/brand/full', [
            'logo' => UploadedFile::fake()->image('company.png', 320, 120),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $asset = $organization->fresh()->currentFullLogo;
        $this->assertNotNull($asset);
        $this->assertSame('full', $asset->variant);
        $this->assertStringStartsWith('organization-branding/', $asset->storage_key);
        $this->assertStringNotContainsString('company', $asset->storage_key);
        Storage::disk('branding-test')->assertExists($asset->storage_key);
        $this->actingAs($admin)->get('/organization-brand/full')
            ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

        [$outsider] = $this->userWithRole('super_admin');
        $this->actingAs($outsider)->get('/organization-brand/full')->assertNotFound();

        $this->actingAs($admin)->delete('/office/settings/organization/brand/full')->assertRedirect();
        $this->assertNull($organization->fresh()->full_logo_asset_id);
        Queue::assertPushed(DeleteUnusedOrganizationBrandAsset::class, fn ($job): bool => $job->assetId === $asset->id);
        $metadata = AuditEvent::query()->where('event_type', 'organization.brand_asset_uploaded')->firstOrFail()->metadata;
        $this->assertArrayNotHasKey('storage_key', $metadata);
    }

    public function test_invalid_and_disallowed_logo_files_are_rejected(): void
    {
        Storage::fake('branding-test');
        config()->set('organization.branding_disk', 'branding-test');
        [$admin] = $this->userWithRole('super_admin');

        $svg = UploadedFile::fake()->createWithContent('brand.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"></svg>');
        $this->actingAs($admin)->post('/office/settings/organization/brand/full', ['logo' => $svg])
            ->assertSessionHasErrors('logo');
        $spoofed = UploadedFile::fake()->createWithContent('brand.png', 'not an image');
        $this->actingAs($admin)->post('/office/settings/organization/brand/full', ['logo' => $spoofed])
            ->assertSessionHasErrors('logo');
        $tooSmall = UploadedFile::fake()->image('small.png', 32, 32);
        $this->actingAs($admin)->post('/office/settings/organization/brand/full', ['logo' => $tooSmall])
            ->assertSessionHasErrors('logo');
    }

    public function test_logo_storage_failure_does_not_create_an_asset_or_change_branding(): void
    {
        config()->set('organization.branding_disk', 'failing-branding');
        $filesystem = Mockery::mock(FilesystemAdapter::class);
        $filesystem->shouldReceive('put')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('failing-branding')->once()->andReturn($filesystem);
        [$admin, $organization] = $this->userWithRole('super_admin');

        $this->actingAs($admin)->post('/office/settings/organization/brand/full', [
            'logo' => UploadedFile::fake()->image('company.png', 320, 120),
        ])->assertSessionHasErrors('logo');

        $this->assertNull($organization->fresh()->full_logo_asset_id);
        $this->assertDatabaseCount('organization_brand_assets', 0);
    }

    /** @return array{User, Organization, OrganizationMembership} */
    private function userWithRole(string $roleKey, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }
}
