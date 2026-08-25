<?php

namespace App\Domain;

use App\Models\AuditEvent;
use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\CloseoutReview;
use App\Models\CloseoutReviewAdjustment;
use App\Models\CloseoutReviewTripCharge;
use App\Models\Invoice;
use App\Models\InvoiceAcknowledgment;
use App\Models\InvoiceCloseout;
use App\Models\InvoiceLine;
use App\Models\OperationalIncident;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketFile;
use App\Models\ServiceTicketNote;
use App\Models\ServiceTicketReopen;
use App\Models\ServiceTicketWorkItem;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Models\VisitMedia;
use App\Models\VisitPartProposal;
use App\Models\VisitTimeEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class FieldTestServiceTicketPurgePreview
{
    /** @return array<string, mixed> */
    public function build(ServiceTicket $ticket): array
    {
        $ticketId = (int) $ticket->id;
        $visitIds = Visit::withTrashed()->where('service_ticket_id', $ticketId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $closeoutIds = Closeout::query()->whereIn('visit_id', $visitIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $reviewIds = CloseoutReview::query()->whereIn('closeout_id', $closeoutIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $handoffIds = BillingHandoff::query()->where('service_ticket_id', $ticketId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $invoiceIds = Invoice::query()
            ->where(fn ($query) => $query->where('service_ticket_id', $ticketId)->orWhereIn('billing_handoff_id', $handoffIds))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $attemptIds = PaymentAttempt::query()->whereIn('invoice_id', $invoiceIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $transactionIds = PaymentTransaction::query()->whereIn('invoice_id', $invoiceIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $receiptIds = PaymentReceipt::query()->whereIn('invoice_id', $invoiceIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $fileIds = ServiceTicketFile::query()->where('service_ticket_id', $ticketId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $mediaIds = VisitMedia::query()->whereIn('visit_id', $visitIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $timeEntryIds = VisitTimeEntry::query()->whereIn('visit_id', $visitIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $partIds = VisitPartProposal::query()->whereIn('visit_id', $visitIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $lineIds = InvoiceLine::query()->whereIn('invoice_id', $invoiceIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $invoiceCloseoutIds = InvoiceCloseout::query()->whereIn('invoice_id', $invoiceIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $acknowledgmentIds = InvoiceAcknowledgment::query()->whereIn('invoice_id', $invoiceIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assignmentIds = VisitAssignment::query()->whereIn('visit_id', $visitIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $adjustmentIds = CloseoutReviewAdjustment::query()->whereIn('closeout_review_id', $reviewIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $tripChargeIds = CloseoutReviewTripCharge::query()->whereIn('closeout_review_id', $reviewIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $noteIds = ServiceTicketNote::query()->where('service_ticket_id', $ticketId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $reopenIds = ServiceTicketReopen::query()->where('service_ticket_id', $ticketId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $projectLinkIds = DB::table('project_service_ticket')->where('service_ticket_id', $ticketId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $workItemIds = ServiceTicketWorkItem::query()->where('service_ticket_id', $ticketId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $workItemVisitIds = DB::table('service_ticket_work_item_visit')->whereIn('service_ticket_work_item_id', $workItemIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $externalFollowUpWorkItemIds = ServiceTicketWorkItem::query()->where('follow_up_service_ticket_id', $ticketId)
            ->where('service_ticket_id', '!=', $ticketId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $externalInvoiceIds = InvoiceCloseout::query()
            ->where(fn ($query) => $query->whereIn('visit_id', $visitIds)->orWhereIn('closeout_id', $closeoutIds))
            ->whereNotIn('invoice_id', $invoiceIds)->pluck('invoice_id')
            ->merge(InvoiceLine::query()->where(fn ($query) => $query
                ->whereIn('source_visit_id', $visitIds)
                ->orWhereIn('source_closeout_id', $closeoutIds)
                ->orWhereIn('source_review_id', $reviewIds)
                ->orWhereIn('source_time_entry_id', $timeEntryIds)
                ->orWhereIn('source_part_proposal_id', $partIds))
                ->whereNotIn('invoice_id', $invoiceIds)->pluck('invoice_id'))
            ->unique()->values()->map(fn ($id) => (int) $id)->all();

        $ids = compact(
            'visitIds', 'closeoutIds', 'reviewIds', 'handoffIds', 'invoiceIds', 'attemptIds', 'transactionIds',
            'receiptIds', 'fileIds', 'mediaIds', 'timeEntryIds', 'partIds', 'lineIds', 'invoiceCloseoutIds',
            'acknowledgmentIds', 'assignmentIds', 'adjustmentIds', 'tripChargeIds', 'noteIds', 'reopenIds', 'projectLinkIds',
            'workItemIds', 'workItemVisitIds'
        );
        $ids['ticketIds'] = [$ticketId];
        $auditIds = $this->referencingAuditIds((int) $ticket->organization_id, $ids);
        $incidentIds = $this->referencingIncidentIds((int) $ticket->organization_id, $ids);
        $ids['auditIds'] = $auditIds;
        $ids['incidentIds'] = $incidentIds;

        $storage = [];
        foreach (ServiceTicketFile::query()->whereIn('id', $fileIds)->get(['storage_disk', 'storage_key']) as $file) {
            $storage[] = ['disk' => $file->storage_disk, 'key' => $file->storage_key, 'kind' => 'ticket_file'];
        }
        foreach (VisitMedia::query()->whereIn('id', $mediaIds)->get(['storage_disk', 'storage_key']) as $media) {
            $storage[] = ['disk' => $media->storage_disk, 'key' => $media->storage_key, 'kind' => 'visit_media'];
        }
        foreach (Invoice::query()->whereIn('id', $invoiceIds)->whereNotNull('pdf_key')->get(['pdf_disk', 'pdf_key']) as $invoice) {
            $storage[] = ['disk' => $invoice->pdf_disk, 'key' => $invoice->pdf_key, 'kind' => 'invoice_pdf'];
        }
        foreach (PaymentReceipt::query()->whereIn('id', $receiptIds)->whereNotNull('pdf_key')->get(['pdf_disk', 'pdf_key']) as $receipt) {
            $storage[] = ['disk' => $receipt->pdf_disk, 'key' => $receipt->pdf_key, 'kind' => 'receipt_pdf'];
        }

        $counts = [
            'notes' => count($noteIds), 'reopens' => count($reopenIds), 'project_links' => count($projectLinkIds),
            'visits' => count($visitIds), 'assignments' => count($assignmentIds), 'time_entries' => count($timeEntryIds),
            'closeouts' => count($closeoutIds), 'reviews' => count($reviewIds), 'review_adjustments' => count($adjustmentIds),
            'trip_charges' => count($tripChargeIds), 'media' => count($mediaIds), 'part_proposals' => count($partIds),
            'billing_handoffs' => count($handoffIds), 'invoices' => count($invoiceIds), 'invoice_lines' => count($lineIds),
            'invoice_closeouts' => count($invoiceCloseoutIds), 'invoice_acknowledgments' => count($acknowledgmentIds),
            'payment_attempts' => count($attemptIds), 'payment_transactions' => count($transactionIds),
            'payment_receipts' => count($receiptIds), 'ticket_files' => count($fileIds), 'audit_events' => count($auditIds),
            'invoice_pdfs' => count(array_filter($storage, fn (array $object): bool => $object['kind'] === 'invoice_pdf')),
            'receipt_pdfs' => count(array_filter($storage, fn (array $object): bool => $object['kind'] === 'receipt_pdf')),
            'operational_incidents' => count($incidentIds), 'private_objects' => count($storage),
            'work_items' => count($workItemIds), 'work_item_visit_touches' => count($workItemVisitIds),
        ];

        return ['ids' => $ids, 'counts' => $counts, 'storage' => $storage, 'blockers' => [
            'external_invoice_ids' => $externalInvoiceIds,
            'external_follow_up_work_item_ids' => $externalFollowUpWorkItemIds,
        ]];
    }

    /** @param array<string, array<int, int>> $ids */
    private function referencingAuditIds(int $organizationId, array $ids): array
    {
        $subjects = $this->subjectMap($ids);

        return AuditEvent::query()->where('organization_id', $organizationId)->get(['id', 'subject_type', 'subject_id', 'metadata'])
            ->filter(fn (AuditEvent $event) => in_array((int) $event->subject_id, $subjects[$event->subject_type] ?? [], true)
                || $this->metadataReferences($event->metadata ?? [], $ids))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param array<string, array<int, int>> $ids */
    private function referencingIncidentIds(int $organizationId, array $ids): array
    {
        $subjects = $this->subjectMap($ids);

        return OperationalIncident::query()->where('organization_id', $organizationId)->get(['id', 'subject_type', 'subject_id', 'context'])
            ->filter(fn (OperationalIncident $incident) => in_array((int) $incident->subject_id, $subjects[$incident->subject_type] ?? [], true)
                || $this->metadataReferences($incident->context ?? [], $ids))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param array<string, array<int, int>> $ids
     * @return array<string, array<int, int>>
     */
    private function subjectMap(array $ids): array
    {
        $mapping = [
            ServiceTicket::class => 'ticketIds', ServiceTicketNote::class => 'noteIds', ServiceTicketReopen::class => 'reopenIds',
            Visit::class => 'visitIds', VisitAssignment::class => 'assignmentIds', VisitTimeEntry::class => 'timeEntryIds',
            VisitMedia::class => 'mediaIds', VisitPartProposal::class => 'partIds', Closeout::class => 'closeoutIds',
            CloseoutReview::class => 'reviewIds', CloseoutReviewAdjustment::class => 'adjustmentIds',
            CloseoutReviewTripCharge::class => 'tripChargeIds', BillingHandoff::class => 'handoffIds', Invoice::class => 'invoiceIds',
            InvoiceLine::class => 'lineIds', InvoiceCloseout::class => 'invoiceCloseoutIds',
            InvoiceAcknowledgment::class => 'acknowledgmentIds', PaymentAttempt::class => 'attemptIds',
            PaymentTransaction::class => 'transactionIds', PaymentReceipt::class => 'receiptIds', ServiceTicketFile::class => 'fileIds',
            ServiceTicketWorkItem::class => 'workItemIds',
        ];
        $subjects = [];
        foreach ($mapping as $class => $key) {
            /** @var Model $model */
            $model = new $class;
            $subjects[$model->getMorphClass()] = $ids[$key] ?? [];
        }

        return $subjects;
    }

    /** @param array<string, mixed> $metadata
     * @param  array<string, array<int, int>>  $ids
     */
    private function metadataReferences(array $metadata, array $ids): bool
    {
        $recordTypes = [
            'service_ticket' => 'ticketIds', 'field_test_service_ticket_purge' => 'ticketIds',
            'visit' => 'visitIds', 'visit_archive' => 'visitIds', 'manual_closeout_visit' => 'visitIds',
            'closeout' => 'closeoutIds', 'invoice' => 'invoiceIds', 'invoice_line' => 'lineIds',
        ];
        if (isset($metadata['record_type'], $metadata['record_id'])
            && isset($recordTypes[$metadata['record_type']])
            && is_numeric($metadata['record_id'])
            && in_array((int) $metadata['record_id'], $ids[$recordTypes[$metadata['record_type']]] ?? [], true)) {
            return true;
        }

        $keys = [
            'ticket_id' => 'ticketIds', 'service_ticket_id' => 'ticketIds', 'visit_id' => 'visitIds', 'source_visit_id' => 'visitIds',
            'return_visit_id' => 'visitIds', 'return_of_visit_id' => 'visitIds', 'visit_ids' => 'visitIds', 'conflicting_visit_ids' => 'visitIds',
            'closeout_id' => 'closeoutIds', 'parent_closeout_id' => 'closeoutIds', 'next_closeout_id' => 'closeoutIds', 'closeout_ids' => 'closeoutIds',
            'review_id' => 'reviewIds', 'closeout_review_id' => 'reviewIds', 'billing_handoff_id' => 'handoffIds', 'handoff_id' => 'handoffIds',
            'invoice_id' => 'invoiceIds', 'current_invoice_id' => 'invoiceIds', 'deleted_invoice_id' => 'invoiceIds',
            'replacement_invoice_id' => 'invoiceIds', 'reissue_of_invoice_id' => 'invoiceIds', 'invoice_line_id' => 'lineIds',
            'attempt_id' => 'attemptIds', 'payment_attempt_id' => 'attemptIds',
            'transaction_id' => 'transactionIds', 'original_transaction_id' => 'transactionIds', 'payment_transaction_id' => 'transactionIds',
            'receipt_id' => 'receiptIds', 'payment_receipt_id' => 'receiptIds', 'file_id' => 'fileIds', 'service_ticket_file_id' => 'fileIds',
            'media_id' => 'mediaIds', 'visit_media_id' => 'mediaIds', 'part_id' => 'partIds', 'visit_part_proposal_id' => 'partIds',
            'proposal_id' => 'partIds', 'source_part_proposal_id' => 'partIds',
            'time_entry_id' => 'timeEntryIds', 'visit_time_entry_id' => 'timeEntryIds', 'source_time_entry_id' => 'timeEntryIds',
            'assignment_id' => 'assignmentIds', 'assignment_ids' => 'assignmentIds', 'reopen_id' => 'reopenIds',
            'work_item_id' => 'workItemIds', 'service_ticket_work_item_id' => 'workItemIds',
        ];
        foreach ($metadata as $key => $value) {
            if (isset($keys[$key]) && is_numeric($value) && in_array((int) $value, $ids[$keys[$key]] ?? [], true)) {
                return true;
            }
            if (isset($keys[$key]) && is_array($value) && array_any($value, fn ($item): bool => is_numeric($item)
                && in_array((int) $item, $ids[$keys[$key]] ?? [], true))) {
                return true;
            }
            if (is_array($value) && $this->metadataReferences($value, $ids)) {
                return true;
            }
        }

        return false;
    }
}
