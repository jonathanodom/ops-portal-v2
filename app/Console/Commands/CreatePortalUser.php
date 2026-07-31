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
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

#[Signature('portal:create-user
    {email : Staff email address}
    {--name= : Staff display name}
    {--organization=NewDay Tech LLC : Organization name}
    {--slug=newday-tech : Organization slug}
    {--role=super_admin : Initial role key}
    {--password= : Password; prompts securely when omitted}')]
#[Description('Create or update a portal staff user and active organization membership')]
class CreatePortalUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $data = [
            'email' => Str::lower((string) $this->argument('email')),
            'name' => (string) ($this->option('name') ?: $this->ask('Display name')),
            'password' => (string) ($this->option('password') ?: $this->secret('Password (12+ characters)')),
            'slug' => (string) $this->option('slug'),
            'role' => (string) $this->option('role'),
        ];

        $validator = Validator::make($data, [
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::min(12)],
            'slug' => ['required', 'alpha_dash'],
            'role' => ['required', 'exists:roles,key'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($data): void {
            $organization = Organization::query()->firstOrCreate(
                ['slug' => $data['slug']],
                ['name' => $this->option('organization'), 'timezone' => 'America/Chicago', 'active' => true],
            );
            $user = User::query()->updateOrCreate(
                ['email' => $data['email']],
                ['name' => $data['name'], 'password' => Hash::make($data['password']), 'status' => 'active'],
            );
            $membership = OrganizationMembership::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $user->id],
                ['status' => 'active'],
            );
            $membership->roles()->syncWithoutDetaching(
                Role::query()->where('key', $data['role'])->pluck('id'),
            );
        });

        $this->info('Portal user is ready.');

        return self::SUCCESS;
    }
}
