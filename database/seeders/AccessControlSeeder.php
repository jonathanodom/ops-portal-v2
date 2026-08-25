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
        'visit_time.correct_submitted' => 'Correct submitted Visit time entries',
        'visit_time.allocate_work' => 'Allocate Visit time across Work Items',
        'visits.archive.manage' => 'Archive, restore, and purge eligible visits',
        'dispatch.manage' => 'Schedule and assign visits',
        'closeouts.review' => 'Review and return closeouts',
        'closeouts.manual_complete' => 'Administratively close and complete eligible visits',
        'billing_handoffs.view' => 'View billing handoff records',
        'billing_handoffs.manage' => 'Acknowledge billing handoff records',
        'invoices.view' => 'View invoice summaries',
        'invoices.manage' => 'Create and edit invoice drafts',
        'invoices.issue' => 'Issue ready invoices',
        'invoices.present' => 'Present issued invoices to customers',
        'invoices.discount' => 'Apply invoice discounts',
        'invoices.void' => 'Void and reissue invoices',
        'invoices.delete_draft' => 'Delete eligible unissued invoice drafts',
        'billing.settings.manage' => 'Manage organization billing settings and labor rates',
        'organization.settings.manage' => 'Manage organization identity, timezone, and branding',
        'payments.view' => 'View payment status and safe transaction summaries',
        'payments.collect' => 'Create and reconcile hosted payment checkouts',
        'payments.record_manual' => 'Record cash, check, and external POS payments',
        'payments.manage_links' => 'Expire payment links and manage receipt links',
        'payments.refund' => 'Refund or reverse successful payments',
        'payments.settings.manage' => 'Manage encrypted payment provider credentials',
        'users.manage' => 'Manage staff access',
        'customers.view' => 'View customer and service-location records',
        'customers.manage' => 'Create and update customer and service-location records',
        'service_tickets.view' => 'View service tickets and visits in the office experience',
        'service_tickets.purge_test_data' => 'Permanently purge Service Ticket test data while destructive field-test tooling is enabled',
        'closeouts.inspect' => 'Inspect submitted field closeout evidence',
        'operations.health.view' => 'View operational health incidents',
        'operations.health.manage' => 'Resolve operational health incidents',
        'catalog.view' => 'View products and services catalog records',
        'catalog.use' => 'Select catalog records in authorized workflows',
        'catalog.manage' => 'Create and maintain catalog records',
        'catalog.pricing.manage' => 'Manage protected catalog pricing and tax defaults',
        'subscriptions.view' => 'View recurring customer Service enrollments',
        'subscriptions.manage' => 'Create and manage recurring customer Service enrollments',
        'projects.view' => 'View Projects and Engagements',
        'projects.manage' => 'Create and update Projects, Workstreams, and Milestones',
        'projects.tasks.manage' => 'Create and update Project Tasks and internal notes',
        'projects.admin' => 'Administer Project relationships and history',
    ];

    private const ROLES = [
        'super_admin' => [
            'name' => 'Super Admin',
            'capabilities' => [],
        ],
        'dispatcher' => [
            'name' => 'Dispatcher',
            'capabilities' => [
                'experience.office.access', 'experience.field.access', 'visits.inspect_all', 'dispatch.manage',
                'customers.view', 'customers.manage',
                'service_tickets.view',
                'closeouts.inspect',
                'catalog.view', 'catalog.use',
                'subscriptions.view', 'subscriptions.manage',
                'projects.view', 'projects.manage', 'projects.tasks.manage', 'projects.admin',
            ],
        ],
        'technician' => [
            'name' => 'Technician',
            'capabilities' => ['experience.field.access', 'visits.execute_assigned', 'customers.view', 'catalog.view', 'catalog.use'],
        ],
        'reviewer' => [
            'name' => 'Reviewer',
            'capabilities' => ['experience.office.access', 'closeouts.review', 'customers.view', 'service_tickets.view', 'closeouts.inspect', 'invoices.view', 'catalog.view', 'subscriptions.view', 'projects.view'],
        ],
        'billing' => [
            'name' => 'Billing',
            'capabilities' => ['experience.office.access', 'billing_handoffs.view', 'billing_handoffs.manage', 'invoices.view', 'invoices.manage', 'invoices.issue', 'invoices.present', 'payments.view', 'payments.collect', 'payments.record_manual', 'payments.manage_links', 'customers.view', 'service_tickets.view', 'catalog.view', 'catalog.use', 'subscriptions.view', 'projects.view'],
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
                $key === 'super_admin'
                    ? Capability::query()->pluck('id')
                    : Capability::query()->whereIn('key', $definition['capabilities'])->pluck('id'),
            );
        }
    }
}
