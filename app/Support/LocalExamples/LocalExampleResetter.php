<?php

namespace App\Support\LocalExamples;

use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class LocalExampleResetter
{
    public const CONFIRMATION = 'RESET LOCAL OPERATIONAL DATA';

    public function __construct(
        private readonly LocalExampleGuard $guard,
        private readonly LocalExampleBootstrapper $bootstrapper,
    ) {}

    /** @return array{backup: string, sha256: string, profile: string, counts: array<string, int>} */
    public function reset(int $organizationId, string $profile, string $confirmation): array
    {
        $organization = $this->guard->organization($organizationId);
        $this->guard->superAdmin($organization);
        $profile = $this->guard->profile($profile);
        if (! hash_equals(self::CONFIRMATION, $confirmation)) {
            throw new RuntimeException('Typed confirmation did not match. No data was changed.');
        }

        $backup = storage_path('app/backups/local-examples-before-reset-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.sqlite');
        if (Artisan::call('ops:backup', ['--output' => $backup]) !== 0 || ! File::exists($backup)) {
            throw new RuntimeException('The required pre-reset backup failed. No data was changed.');
        }
        if (Artisan::call('ops:restore-verify', ['backup' => $backup]) !== 0) {
            throw new RuntimeException('Backup restore verification failed. No data was changed.');
        }

        $objects = $this->privateObjects($organization);
        $counts = [];
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($organization, $profile, &$counts): void {
                $this->deleteOperationalData($organization);
                $this->deleteExampleCatalog($organization);
                $counts = $this->bootstrapper->bootstrap($organization, $profile);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        foreach ($objects as $disk => $keys) {
            Storage::disk($disk)->delete($keys);
        }

        return ['backup' => $backup, 'sha256' => hash_file('sha256', $backup), 'profile' => $profile, 'counts' => $counts];
    }

    /** @return array<string, array<int, string>> */
    private function privateObjects(Organization $organization): array
    {
        $objects = [];
        foreach (['visit_media', 'service_ticket_files'] as $table) {
            DB::table($table)->where('organization_id', $organization->id)
                ->whereNotNull('storage_disk')->whereNotNull('storage_key')
                ->select(['storage_disk', 'storage_key'])->get()
                ->each(function (object $row) use (&$objects): void {
                    $objects[$row->storage_disk][] = $row->storage_key;
                });
        }
        DB::table('closeout_acknowledgment_signatures')->where('organization_id', $organization->id)
            ->select(['storage_disk', 'storage_key'])->get()
            ->each(function (object $row) use (&$objects): void {
                $objects[$row->storage_disk][] = $row->storage_key;
            });

        foreach (DB::table('invoices')->where('organization_id', $organization->id)->whereNotNull('pdf_disk')->whereNotNull('pdf_key')->get(['pdf_disk', 'pdf_key']) as $row) {
            $objects[$row->pdf_disk][] = $row->pdf_key;
        }
        foreach (DB::table('payment_receipts')->where('organization_id', $organization->id)->whereNotNull('pdf_disk')->whereNotNull('pdf_key')->get(['pdf_disk', 'pdf_key']) as $row) {
            $objects[$row->pdf_disk][] = $row->pdf_key;
        }

        return $objects;
    }

    private function deleteOperationalData(Organization $organization): void
    {
        $subjectTypes = [
            'Customer', 'Contact', 'ServiceLocation', 'CustomerServiceEnrollment', 'ServiceTicket',
            'ServiceTicketNote', 'ServiceTicketReopen', 'ServiceTicketFile', 'Visit', 'VisitAssignment',
            'VisitTimeEntry', 'VisitMedia', 'VisitPartProposal', 'Closeout', 'CloseoutReview',
            'CloseoutReviewAdjustment', 'CloseoutReviewTripCharge', 'BillingHandoff', 'Invoice',
            'InvoiceLine', 'InvoiceAcknowledgment', 'PaymentAttempt', 'PaymentTransaction',
            'PaymentReceipt', 'Project', 'ProjectWorkstream', 'ProjectTask', 'ProjectMilestone', 'ProjectNote',
        ];
        AuditEvent::query()->where('organization_id', $organization->id)
            ->where(function ($query) use ($subjectTypes): void {
                foreach ($subjectTypes as $type) {
                    $query->orWhere('subject_type', 'like', "%{$type}");
                }
            })
            ->orWhere(function ($query) use ($organization): void {
                $query->where('organization_id', $organization->id)
                    ->where('event_type', 'local_examples.bootstrapped');
            })
            ->delete();

        foreach (LocalExampleInventory::OPERATIONAL_TABLES as $table) {
            DB::table($table)->where('organization_id', $organization->id)->delete();
        }
        DB::table('payment_provider_authorization_states')->where('organization_id', $organization->id)->delete();
    }

    private function deleteExampleCatalog(Organization $organization): void
    {
        $serviceIds = DB::table('catalog_services')->where('organization_id', $organization->id)->where('service_code', 'like', 'EXAMPLE-%')->pluck('id');
        $productIds = DB::table('catalog_products')->where('organization_id', $organization->id)->where('product_code', 'like', 'EXAMPLE-%')->pluck('id');
        $packageIds = DB::table('catalog_packages')->where('organization_id', $organization->id)->where('package_code', 'like', 'EXAMPLE-%')->pluck('id');
        DB::table('catalog_service_addons')->where('organization_id', $organization->id)->where(function ($query) use ($serviceIds): void {
            $query->whereIn('catalog_service_id', $serviceIds)->orWhereIn('addon_service_id', $serviceIds);
        })->delete();
        DB::table('catalog_service_variants')->whereIn('catalog_service_id', $serviceIds)->delete();
        DB::table('catalog_package_components')->whereIn('catalog_package_id', $packageIds)
            ->orWhereIn('catalog_product_id', $productIds)->orWhereIn('catalog_service_id', $serviceIds)->delete();
        DB::table('catalog_product_purchase_units')->whereIn('catalog_product_id', $productIds)->delete();
        DB::table('catalog_packages')->whereIn('id', $packageIds)->delete();
        DB::table('catalog_products')->whereIn('id', $productIds)->delete();
        DB::table('catalog_services')->whereIn('id', $serviceIds)->delete();
        DB::table('catalog_categories')->where('organization_id', $organization->id)->where('code', 'like', 'EXAMPLE-%')->delete();
    }
}
