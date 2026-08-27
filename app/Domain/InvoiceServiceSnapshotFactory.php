<?php

namespace App\Domain;

use App\Models\Invoice;
use App\Models\InvoiceServiceSnapshot;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceServiceSnapshotFactory
{
    public function __construct(
        private readonly InvoiceServiceContextProjection $projection,
        private readonly AuditRecorder $audit,
    ) {}

    public function createForIssue(Invoice $invoice, User $actor): ?InvoiceServiceSnapshot
    {
        if ($invoice->isDirect()) {
            return null;
        }

        $existing = InvoiceServiceSnapshot::query()->where('invoice_id', $invoice->id)->first();
        if ($existing) {
            $this->assertIntegrity($existing, $invoice);

            return $existing;
        }

        if (! DB::transactionLevel()) {
            throw new \LogicException('Invoice service snapshots must be created inside the issue transaction.');
        }

        $payload = $this->projection->build($invoice);
        $canonicalJson = self::canonicalJson($payload);
        $snapshot = InvoiceServiceSnapshot::query()->create([
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->id,
            'service_ticket_id' => $invoice->service_ticket_id,
            'schema_version' => InvoiceServiceContextProjection::SCHEMA_VERSION,
            'snapshot_json' => $payload,
            'snapshot_sha256' => hash('sha256', $canonicalJson),
            'captured_at' => now(),
            'captured_by_id' => $actor->id,
        ]);
        $this->audit->record($invoice->organization, $actor, 'invoice.service_context_snapshotted', $invoice, [
            'invoice_id' => $invoice->id,
            'service_ticket_id' => $invoice->service_ticket_id,
            'snapshot_id' => $snapshot->id,
            'schema_version' => InvoiceServiceContextProjection::SCHEMA_VERSION,
            'sha256' => $snapshot->snapshot_sha256,
            'visit_count' => count($payload['visits']),
            'work_item_count' => count($payload['work_items']),
        ]);

        return $snapshot;
    }

    /** @param array<string, mixed> $payload */
    public static function canonicalJson(array $payload): string
    {
        return json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function assertIntegrity(InvoiceServiceSnapshot $snapshot, Invoice $invoice): void
    {
        if ((int) $snapshot->organization_id !== (int) $invoice->organization_id
            || (int) $snapshot->invoice_id !== (int) $invoice->id
            || (int) $snapshot->service_ticket_id !== (int) $invoice->service_ticket_id) {
            throw ValidationException::withMessages(['invoice' => 'The stored Service Ticket context does not match this invoice.']);
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::canonicalize(...), $value);
    }
}
