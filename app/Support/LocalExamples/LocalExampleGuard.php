<?php

namespace App\Support\LocalExamples;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Support\Str;
use RuntimeException;

final class LocalExampleGuard
{
    public function organization(int $organizationId): Organization
    {
        $this->environment();

        $organization = Organization::query()->find($organizationId);
        if (! $organization || ! $organization->active) {
            throw new RuntimeException('Select an active Organization that exists in this local database.');
        }

        return $organization;
    }

    public function superAdmin(Organization $organization): OrganizationMembership
    {
        $memberships = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->whereHas('roles', fn ($query) => $query->where('key', 'super_admin'))
            ->with('user')
            ->get();

        if ($memberships->count() !== 1) {
            throw new RuntimeException('The selected Organization must have exactly one active Super Admin for local examples.');
        }

        return $memberships->first();
    }

    public function profile(string $profile): string
    {
        if (! in_array($profile, ['small', 'full'], true)) {
            throw new RuntimeException('Example profile must be small or full.');
        }

        return $profile;
    }

    public function environment(): void
    {
        if (app()->environment('testing') && config('local_examples.allow_testing') === true) {
            return;
        }

        if (! app()->environment('local')) {
            throw new RuntimeException('Local examples are available only when APP_ENV=local.');
        }
        if (config('database.default') !== 'sqlite') {
            throw new RuntimeException('Local examples require the SQLite development database.');
        }

        $configured = (string) config('database.connections.sqlite.database');
        if ($configured === ':memory:' || Str::contains(Str::lower($configured), 'beta')) {
            throw new RuntimeException('Refusing an in-memory or beta database.');
        }
        $configured = $this->absolutePath($configured);
        $expected = database_path('database.sqlite');
        $configuredReal = realpath($configured) ?: $configured;
        $expectedReal = realpath($expected) ?: $expected;
        if (Str::lower(str_replace('\\', '/', $configuredReal)) !== Str::lower(str_replace('\\', '/', $expectedReal))) {
            throw new RuntimeException('Local examples may target only database/database.sqlite.');
        }
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
            ? $path
            : base_path($path);
    }
}
