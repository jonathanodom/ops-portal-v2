<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class BetaScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AccessControlSeeder::class);
        $password = (string) env('BETA_DEMO_PASSWORD');
        if ($password === '' || str_contains($password, 'replace-with')) {
            throw new \RuntimeException('Set BETA_DEMO_PASSWORD in the untracked .env.beta before seeding.');
        }

        $organization = Organization::query()->create(['name' => 'NewDay Beta Validation', 'slug' => 'beta-validation', 'timezone' => 'America/Chicago', 'active' => true]);
        $memberships = [];
        foreach (['super_admin' => 'Super Admin', 'dispatcher' => 'Dispatcher', 'technician' => 'Technician', 'reviewer' => 'Reviewer', 'billing' => 'Billing'] as $roleKey => $name) {
            $user = User::query()->create([
                'name' => "Beta {$name}",
                'email' => "beta.{$roleKey}@newdaytech.test",
                'password' => Hash::make($password),
                'status' => 'active',
            ]);
            $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
            $membership->roles()->sync([Role::query()->where('key', $roleKey)->valueOrFail('id')]);
            $memberships[$roleKey] = $membership;
        }

        $scenarios = [
            ['code' => 'A', 'title' => 'Resolved with photo and acknowledgment', 'description' => 'Complete this visit as resolved, attach an after photo, capture customer acknowledgment, approve it, and acknowledge its billing handoff.'],
            ['code' => 'B', 'title' => 'Needs return trip and second visit', 'description' => 'Submit this visit as needs return trip, approve it, schedule and complete the linked return visit, then approve the resolved return.'],
            ['code' => 'C', 'title' => 'Return, correct, resubmit, and approve', 'description' => 'Submit this visit, return it from office with instructions, revise the new version, resubmit it, and approve it.'],
        ];

        foreach ($scenarios as $offset => $scenario) {
            $customer = Customer::query()->create([
                'organization_id' => $organization->id, 'type' => 'business',
                'display_name' => "BETA Scenario {$scenario['code']} Customer", 'status' => 'active',
                'created_by_id' => $memberships['super_admin']->user_id, 'updated_by_id' => $memberships['super_admin']->user_id,
            ]);
            $contact = Contact::query()->create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id,
                'name' => "Scenario {$scenario['code']} Representative", 'email' => "scenario{$scenario['code']}@example.test",
                'is_preferred' => true, 'active' => true,
            ]);
            $location = ServiceLocation::query()->create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id, 'primary_contact_id' => $contact->id,
                'name' => "BETA Scenario {$scenario['code']} Site", 'address_line_1' => (100 + $offset).' Beta Way',
                'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'timezone' => 'America/Chicago',
                'access_instructions' => 'Use the marked beta entrance.', 'is_primary' => true, 'active' => true,
            ]);
            $ticket = ServiceTicket::query()->create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id,
                'contact_id' => $contact->id, 'ticket_number' => 'NDT-ST-2026-'.(9001 + $offset), 'title' => "BETA {$scenario['code']}: {$scenario['title']}",
                'description' => $scenario['description'], 'customer_visible_summary' => 'Beta workflow validation only.',
                'priority' => $offset === 1 ? 'high' : 'normal', 'source' => 'internal', 'status' => 'open',
                'created_by_id' => $memberships['dispatcher']->user_id, 'updated_by_id' => $memberships['dispatcher']->user_id,
            ]);
            $visit = Visit::query()->create([
                'organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id,
                'status' => 'assigned', 'timezone' => 'America/Chicago',
                'scheduled_start_at' => now()->startOfDay()->addHours(14 + $offset),
                'scheduled_end_at' => now()->startOfDay()->addHours(15 + $offset),
                'scheduled_by_id' => $memberships['dispatcher']->user_id,
                'created_by_id' => $memberships['dispatcher']->user_id, 'updated_by_id' => $memberships['dispatcher']->user_id,
            ]);
            VisitAssignment::query()->create([
                'organization_id' => $organization->id, 'visit_id' => $visit->id,
                'organization_membership_id' => $memberships['technician']->id, 'is_lead' => true,
                'assigned_by_id' => $memberships['dispatcher']->user_id,
            ]);
        }

        $this->call(BetaVolumeSeeder::class);
    }
}
