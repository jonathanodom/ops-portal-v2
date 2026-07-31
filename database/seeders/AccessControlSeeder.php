<?php

namespace Database\Seeders;

use App\Models\Capability;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AccessControlSeeder extends Seeder
{
    private const CAPABILITIES = [
        'experience.office.access' => 'Access the office experience',
        'experience.field.access' => 'Access the field experience',
        'visits.inspect_all' => 'Inspect all authorized visits',
        'visits.execute_assigned' => 'Execute assigned visits',
        'visits.execute_any' => 'Execute any visit for approved testing',
        'dispatch.manage' => 'Schedule and assign visits',
        'closeouts.review' => 'Review and return closeouts',
        'billing_handoffs.view' => 'View billing handoff records',
        'users.manage' => 'Manage staff access',
        'customers.view' => 'View customer and service-location records',
        'customers.manage' => 'Create and update customer and service-location records',
        'service_tickets.view' => 'View service tickets and visits in the office experience',
        'closeouts.inspect' => 'Inspect submitted field closeout evidence',
    ];

    private const ROLES = [
        'super_admin' => [
            'name' => 'Super Admin',
            'capabilities' => [
                'experience.office.access', 'experience.field.access', 'visits.inspect_all',
                'dispatch.manage', 'closeouts.review', 'billing_handoffs.view', 'users.manage',
                'customers.view', 'customers.manage',
                'service_tickets.view',
                'closeouts.inspect',
            ],
        ],
        'dispatcher' => [
            'name' => 'Dispatcher',
            'capabilities' => [
                'experience.office.access', 'experience.field.access', 'visits.inspect_all', 'dispatch.manage',
                'customers.view', 'customers.manage',
                'service_tickets.view',
                'closeouts.inspect',
            ],
        ],
        'technician' => [
            'name' => 'Technician',
            'capabilities' => ['experience.field.access', 'visits.execute_assigned', 'customers.view'],
        ],
        'reviewer' => [
            'name' => 'Reviewer',
            'capabilities' => ['experience.office.access', 'closeouts.review', 'customers.view', 'service_tickets.view', 'closeouts.inspect'],
        ],
        'billing' => [
            'name' => 'Billing',
            'capabilities' => ['experience.office.access', 'billing_handoffs.view', 'customers.view', 'service_tickets.view'],
        ],
    ];

    public function run(): void
    {
        foreach (self::CAPABILITIES as $key => $name) {
            Capability::query()->updateOrCreate(['key' => $key], ['name' => $name]);
        }

        foreach (self::ROLES as $key => $definition) {
            $role = Role::query()->updateOrCreate(['key' => $key], ['name' => $definition['name']]);
            $role->capabilities()->sync(
                Capability::query()->whereIn('key', $definition['capabilities'])->pluck('id'),
            );
        }
    }
}
