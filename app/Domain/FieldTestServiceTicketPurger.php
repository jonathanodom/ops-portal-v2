<?php

namespace App\Domain;

use App\Exceptions\FieldTestPurgeStorageCleanupException;
use App\Models\FieldTestPurgeCleanup;
use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FieldTestServiceTicketPurger
{
    public function __construct(private readonly FieldTestServiceTicketPurgePreview $preview) {}

    /** @return array{cleanup: FieldTestPurgeCleanup, counts: array<string, int>} */
    public function purge(ServiceTicket $ticket, User $actor, string $confirmedTicketNumber, bool $acknowledged): array
    {
        $result = DB::transaction(function () use ($ticket, $actor, $confirmedTicketNumber, $acknowledged): array {
            $ticket = ServiceTicket::query()
                ->where('organization_id', $ticket->organization_id)
                ->lockForUpdate()
                ->findOrFail($ticket->id);

            if (! hash_equals($ticket->ticket_number, $confirmedTicketNumber)) {
                throw ValidationException::withMessages(['ticket_number' => 'Enter the exact current Service Ticket number.']);
            }
            if (! $acknowledged) {
                throw ValidationException::withMessages(['acknowledge' => 'You must acknowledge that this permanently destroys the listed test data.']);
            }

            $graph = $this->preview->build($ticket);
            if ($graph['blockers']['external_invoice_ids'] !== []) {
                throw ValidationException::withMessages([
                    'service_ticket' => 'Purge is blocked because an Invoice outside this Ticket aggregate references its operational records.',
                ]);
            }
            if ($graph['blockers']['external_follow_up_work_item_ids'] !== []) {
                throw ValidationException::withMessages([
                    'service_ticket' => 'Purge is blocked because another Ticket retains this Ticket as Work Item follow-up provenance.',
                ]);
            }

            $cleanup = FieldTestPurgeCleanup::query()->create([
                'public_id' => (string) Str::uuid(),
                'organization_id' => $ticket->organization_id,
                'actor_id' => $actor->id,
                'storage_manifest' => $graph['storage'],
                'record_counts' => $graph['counts'],
                'status' => 'pending',
            ]);
            $ids = $graph['ids'];

            DB::table('audit_events')->whereIn('id', $ids['auditIds'])->delete();
            DB::table('operational_incidents')->whereIn('id', $ids['incidentIds'])->delete();
            DB::table('payment_receipts')->whereIn('id', $ids['receiptIds'])->delete();
            DB::table('payment_transactions')->whereIn('id', $ids['transactionIds'])->update(['original_transaction_id' => null]);
            DB::table('payment_transactions')->whereIn('id', $ids['transactionIds'])->delete();
            DB::table('payment_attempts')->whereIn('id', $ids['attemptIds'])->delete();
            DB::table('invoice_acknowledgments')->whereIn('id', $ids['acknowledgmentIds'])->delete();
            DB::table('invoice_closeouts')->whereIn('id', $ids['invoiceCloseoutIds'])->delete();
            DB::table('invoice_lines')->whereIn('id', $ids['lineIds'])->delete();
            DB::table('billing_handoffs')->whereIn('id', $ids['handoffIds'])->update(['current_invoice_id' => null]);
            DB::table('invoices')->whereIn('id', $ids['invoiceIds'])->update(['reissue_of_invoice_id' => null]);
            DB::table('invoices')->whereIn('id', $ids['invoiceIds'])->delete();
            DB::table('billing_handoffs')->whereIn('id', $ids['handoffIds'])->delete();
            DB::table('closeout_review_adjustments')->whereIn('id', $ids['adjustmentIds'])->delete();
            DB::table('closeout_review_trip_charges')->whereIn('id', $ids['tripChargeIds'])->delete();
            DB::table('closeout_reviews')->whereIn('id', $ids['reviewIds'])->delete();
            DB::table('visits')->whereIn('id', $ids['visitIds'])->update(['current_closeout_id' => null, 'return_of_visit_id' => null]);
            DB::table('closeouts')->whereIn('id', $ids['closeoutIds'])->update(['parent_closeout_id' => null, 'return_visit_id' => null]);
            DB::table('visit_part_proposals')->whereIn('id', $ids['partIds'])->update(['source_proposal_id' => null]);
            DB::table('visit_time_entries')->whereIn('id', $ids['timeEntryIds'])->delete();
            DB::table('visit_media')->whereIn('id', $ids['mediaIds'])->delete();
            DB::table('visit_part_proposals')->whereIn('id', $ids['partIds'])->delete();
            DB::table('closeouts')->whereIn('id', $ids['closeoutIds'])->delete();
            DB::table('visit_assignments')->whereIn('id', $ids['assignmentIds'])->delete();
            DB::table('service_ticket_work_item_visit')->whereIn('id', $ids['workItemVisitIds'])->delete();
            DB::table('service_ticket_work_items')->whereIn('id', $ids['workItemIds'])->delete();
            DB::table('visits')->whereIn('id', $ids['visitIds'])->delete();
            DB::table('service_ticket_files')->whereIn('id', $ids['fileIds'])->delete();
            DB::table('service_ticket_notes')->whereIn('id', $ids['noteIds'])->delete();
            DB::table('service_ticket_reopens')->whereIn('id', $ids['reopenIds'])->delete();
            DB::table('project_service_ticket')->whereIn('id', $ids['projectLinkIds'])->delete();

            $this->beforeTicketDelete($ticket);
            $ticket->delete();

            return ['cleanup' => $cleanup, 'counts' => $graph['counts']];
        });

        $this->cleanupStorage($result['cleanup']);
        Log::info('Field-test Service Ticket purge completed.', [
            'organization_id' => $result['cleanup']->organization_id,
            'actor_id' => $actor->id,
            'counts' => $result['counts'],
        ]);

        return $result;
    }

    public function retryCleanup(FieldTestPurgeCleanup $cleanup): void
    {
        $this->cleanupStorage($cleanup->fresh());
    }

    protected function beforeTicketDelete(ServiceTicket $ticket): void {}

    private function cleanupStorage(FieldTestPurgeCleanup $cleanup): void
    {
        try {
            foreach ($cleanup->storage_manifest as $object) {
                $disk = Storage::disk($object['disk']);
                if (! $disk->exists($object['key'])) {
                    continue;
                }
                if (! $disk->delete($object['key']) || $disk->exists($object['key'])) {
                    throw new \RuntimeException('Private object deletion was not confirmed.');
                }
            }
            $cleanup->update(['status' => 'completed', 'completed_at' => now()]);
        } catch (Throwable $exception) {
            $cleanup->increment('failure_count');
            $cleanup->update(['status' => 'failed', 'completed_at' => null]);
            Log::error('Field-test purge storage cleanup incomplete.', [
                'organization_id' => $cleanup->organization_id,
                'actor_id' => $cleanup->actor_id,
                'cleanup_id' => $cleanup->public_id,
                'failure_code' => class_basename($exception),
            ]);
            throw new FieldTestPurgeStorageCleanupException($cleanup->public_id);
        }
    }
}
