<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('jarvis:create-service-account
    {--email=jarvis-core@service.newdaytech.net : Service account email}
    {--name=JARVIS Core : Service account display name}
    {--organization= : Organization slug or numeric ID; required when more than one active organization exists}
    {--rotate : Revoke existing personal access tokens for this account and issue a new one}')]
#[Description('Create (or rotate the token for) the dedicated JARVIS service identity and its scoped access token')]
class CreateJarvisServiceAccount extends Command
{
    private const ABILITIES = [
        'customers.read', 'contacts.read', 'locations.read', 'projects.read',
        'tickets.read', 'tickets.create', 'tickets.update', 'communications.create',
    ];

    public function handle(): int
    {
        $organization = $this->resolveOrganization();
        if (! $organization) {
            return self::FAILURE;
        }

        $role = Role::query()->where('key', 'jarvis_service')->first();
        if (! $role) {
            $this->error('The "jarvis_service" role does not exist. Run migrations first (php artisan migrate).');

            return self::FAILURE;
        }

        $email = Str::lower((string) $this->option('email'));
        $name = (string) $this->option('name');

        [$user, $token] = DB::transaction(function () use ($organization, $email, $name): array {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    // Random, never displayed, never usable for session login: status below
                    // is intentionally not "active", so Auth::attempt (which requires
                    // status = active) can never authenticate this account with a password.
                    'password' => Hash::make(Str::random(64)),
                    'status' => 'service_account',
                ],
            );

            $membership = OrganizationMembership::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $user->id],
                ['status' => 'active'],
            );
            $membership->roles()->syncWithoutDetaching(Role::query()->where('key', 'jarvis_service')->pluck('id'));

            if ($this->option('rotate')) {
                $user->tokens()->delete();
            }

            $result = $user->createToken($name, self::ABILITIES);

            return [$user, $result->plainTextToken];
        });

        $this->info('JARVIS service identity is ready.');
        $this->line('  User ID: '.$user->id);
        $this->line('  Organization: '.$organization->name." (id {$organization->id})");
        $this->line('  Scopes: '.implode(', ', self::ABILITIES));
        $this->newLine();
        $this->warn('Bearer token (shown once, not recoverable — store as OPS_API_TOKEN in the JARVIS environment):');
        $this->line($token);

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $option = $this->option('organization');
        if ($option) {
            $organization = ctype_digit((string) $option)
                ? Organization::query()->find((int) $option)
                : Organization::query()->where('slug', $option)->first();
            if (! $organization) {
                $this->error("No organization matches \"{$option}\".");

                return null;
            }

            return $organization;
        }

        $active = Organization::query()->where('active', true)->get();
        if ($active->count() === 1) {
            return $active->first();
        }

        if ($active->isEmpty()) {
            $this->error('No active organization exists. Create one first.');
        } else {
            $this->error('Multiple active organizations exist; specify --organization=<slug-or-id>.');
        }

        return null;
    }
}
