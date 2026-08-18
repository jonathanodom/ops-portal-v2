<?php

namespace App\Support\LocalExamples;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

final class LocalExampleInventory
{
    public const SMALL_EXPECTED = [
        'customers' => 8,
        'service_locations' => 10,
        'service_tickets' => 12,
        'visits' => 13,
        'closeouts' => 8,
        'visit_media' => 5,
        'service_ticket_files' => 2,
        'projects' => 2,
        'invoices' => 9,
    ];

    public const FULL_EXPECTED = [
        'customers' => 250,
        'service_locations' => 400,
        'service_tickets' => 500,
        'visits' => 1000,
        'closeouts' => 200,
        'visit_media' => 500,
        'service_ticket_files' => 2,
        'projects' => 2,
        'invoices' => 9,
    ];

    public const OPERATIONAL_TABLES = [
        'customers', 'contacts', 'service_locations', 'customer_service_enrollments',
        'service_tickets', 'service_ticket_notes', 'service_ticket_reopens', 'service_ticket_files',
        'visits', 'visit_assignments', 'visit_time_entries', 'visit_media', 'visit_part_proposals',
        'closeouts', 'closeout_reviews', 'closeout_review_adjustments', 'closeout_review_trip_charges',
        'billing_handoffs', 'invoices', 'invoice_lines', 'invoice_closeouts', 'invoice_acknowledgments',
        'payment_attempts', 'payment_transactions', 'payment_receipts', 'payment_webhook_events',
        'projects', 'project_workstreams', 'project_tasks', 'project_milestones', 'project_notes',
        'project_service_ticket', 'operational_incidents',
    ];

    public function inspect(Organization $organization): array
    {
        $counts = [];
        foreach (self::OPERATIONAL_TABLES as $table) {
            $counts[$table] = DB::table($table)->where('organization_id', $organization->id)->count();
        }

        $exampleCounts = [
            'customers' => DB::table('customers')->where('organization_id', $organization->id)->where('display_name', 'like', 'EXAMPLE%')->count(),
            'service_locations' => DB::table('service_locations')->where('organization_id', $organization->id)->where('name', 'like', 'EXAMPLE%')->count(),
            'service_tickets' => DB::table('service_tickets')->where('organization_id', $organization->id)->where('title', 'like', 'EXAMPLE%')->count(),
            'visits' => DB::table('visits')->where('organization_id', $organization->id)->count(),
            'closeouts' => DB::table('closeouts')->where('organization_id', $organization->id)->count(),
            'visit_media' => DB::table('visit_media')->where('organization_id', $organization->id)->count(),
            'service_ticket_files' => DB::table('service_ticket_files')->where('organization_id', $organization->id)->count(),
            'projects' => DB::table('projects')->where('organization_id', $organization->id)->where('name', 'like', 'EXAMPLE%')->count(),
            'invoices' => DB::table('invoices')->where('organization_id', $organization->id)->count(),
        ];

        $catalog = [
            'services' => DB::table('catalog_services')->where('organization_id', $organization->id)->where('service_code', 'like', 'EXAMPLE-%')->count(),
            'products' => DB::table('catalog_products')->where('organization_id', $organization->id)->where('product_code', 'like', 'EXAMPLE-%')->count(),
            'packages' => DB::table('catalog_packages')->where('organization_id', $organization->id)->where('package_code', 'like', 'EXAMPLE-%')->count(),
        ];

        return compact('counts', 'exampleCounts', 'catalog') + [
            'storage_objects' => DB::table('visit_media')->where('organization_id', $organization->id)->count()
                + DB::table('service_ticket_files')->where('organization_id', $organization->id)->count(),
        ];
    }

    public function status(Organization $organization, string $profile): string
    {
        $actual = $this->inspect($organization);
        $expected = $profile === 'full' ? self::FULL_EXPECTED : self::SMALL_EXPECTED;
        $matches = collect($expected)->every(fn (int $count, string $table): bool => ($actual['exampleCounts'][$table] ?? -1) === $count);
        if ($matches && $actual['catalog']['services'] === 2 && $actual['catalog']['products'] === 4 && $actual['catalog']['packages'] === 1) {
            return 'complete';
        }

        $hasExamples = collect($actual['exampleCounts'])->sum() > 0
            || collect($actual['catalog'])->sum() > 0
            || collect($actual['counts'])->sum() > 0;

        return $hasExamples ? 'partial' : 'absent';
    }
}
