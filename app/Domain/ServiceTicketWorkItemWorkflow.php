<?php

namespace App\Domain;

use App\Models\Organization;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketWorkItem;
use App\Models\User;
use App\Models\Visit;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServiceTicketWorkItemWorkflow
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly ServiceTicketCompletion $completion,
        private readonly ServiceTicketCreator $ticketCreator,
    ) {}

    public function createFromField(Visit $visit, User $actor, array $data): ServiceTicketWorkItem
    {
        return DB::transaction(function () use ($visit, $actor, $data): ServiceTicketWorkItem {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            $ticket = $visit->serviceTicket()->lockForUpdate()->firstOrFail();
            $this->assertFieldWritable($visit, $ticket);

            $item = ServiceTicketWorkItem::query()->create([
                'organization_id' => $ticket->organization_id,
                'service_ticket_id' => $ticket->id,
                'discovered_visit_id' => $visit->id,
                'origin' => 'field_discovered',
                'title' => $data['title'],
                'detail' => $data['detail'] ?? null,
                'work_note' => $data['work_note'] ?? null,
                'status' => 'open',
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
                'status_changed_by_id' => $actor->id,
                'status_changed_at' => now(),
            ]);
            $this->touch($item, $visit, $actor);
            $this->audit->record($ticket->organization, $actor, 'service_ticket.work_item_created', $ticket, [
                'ticket_id' => $ticket->id, 'work_item_id' => $item->id, 'visit_id' => $visit->id, 'origin' => $item->origin,
            ]);

            return $item->load('visits');
        });
    }

    public function updateFromField(ServiceTicketWorkItem $item, Visit $visit, User $actor, array $data): ServiceTicketWorkItem
    {
        return DB::transaction(function () use ($item, $visit, $actor, $data): ServiceTicketWorkItem {
            $item = ServiceTicketWorkItem::query()->lockForUpdate()->findOrFail($item->id);
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            $ticket = $visit->serviceTicket()->lockForUpdate()->firstOrFail();
            $this->assertSameContext($item, $visit, $ticket);
            $this->assertFieldWritable($visit, $ticket);
            if ($item->status === 'transferred') {
                $this->reject('work_item', 'Transferred Work Items are read-only.');
            }

            $status = $data['status'];
            if (! in_array($status, ['open', 'completed', 'needs_follow_up'], true)) {
                $this->reject('status', 'Field users may choose Open, Completed, or Needs follow-up.');
            }
            $oldStatus = $item->status;
            if ($status !== $oldStatus) {
                $this->assertNoActiveTimerForTerminalStatus($item, $status);
            }
            $item->update([
                'work_note' => $data['work_note'] ?? null,
                'status' => $status,
                'updated_by_id' => $actor->id,
                'status_changed_by_id' => $status !== $oldStatus ? $actor->id : $item->status_changed_by_id,
                'status_changed_at' => $status !== $oldStatus ? now() : $item->status_changed_at,
            ]);
            $this->touch($item, $visit, $actor);
            $this->recordUpdate($item, $ticket, $actor, $oldStatus, ['work_note', ...($status !== $oldStatus ? ['status'] : [])], $visit->id);

            return $item->refresh()->load('visits');
        });
    }

    public function createFromOffice(ServiceTicket $ticket, User $actor, array $data): ServiceTicketWorkItem
    {
        return DB::transaction(function () use ($ticket, $actor, $data): ServiceTicketWorkItem {
            $ticket = ServiceTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $this->assertTicketWritable($ticket);
            $item = ServiceTicketWorkItem::query()->create([
                'organization_id' => $ticket->organization_id,
                'service_ticket_id' => $ticket->id,
                'origin' => 'office_added',
                'title' => $data['title'],
                'detail' => $data['detail'] ?? null,
                'work_note' => $data['work_note'] ?? null,
                'status' => $data['status'] ?? 'open',
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
                'status_changed_by_id' => $actor->id,
                'status_changed_at' => now(),
            ]);
            $this->audit->record($ticket->organization, $actor, 'service_ticket.work_item_created', $ticket, [
                'ticket_id' => $ticket->id, 'work_item_id' => $item->id, 'origin' => $item->origin,
            ]);

            return $item;
        });
    }

    public function updateFromOffice(ServiceTicketWorkItem $item, User $actor, array $data): ServiceTicketWorkItem
    {
        return DB::transaction(function () use ($item, $actor, $data): ServiceTicketWorkItem {
            $item = ServiceTicketWorkItem::query()->lockForUpdate()->findOrFail($item->id);
            $ticket = $item->serviceTicket()->lockForUpdate()->firstOrFail();
            $this->assertTicketWritable($ticket);
            if ($item->status === 'transferred') {
                $this->reject('work_item', 'Transferred Work Items are read-only.');
            }
            $status = $data['status'];
            if (! in_array($status, ['open', 'completed', 'needs_follow_up', 'canceled'], true)) {
                $this->reject('status', 'Choose a valid Office Work Item status.');
            }
            $oldStatus = $item->status;
            if ($status !== $oldStatus) {
                $this->assertNoActiveTimerForTerminalStatus($item, $status);
            }
            $changed = collect(['title', 'detail', 'work_note', 'status'])
                ->filter(fn (string $field): bool => ($item->{$field} ?? null) !== ($data[$field] ?? null))->values()->all();
            $item->update([
                'title' => $data['title'], 'detail' => $data['detail'] ?? null, 'work_note' => $data['work_note'] ?? null,
                'status' => $status, 'updated_by_id' => $actor->id,
                'status_changed_by_id' => $status !== $oldStatus ? $actor->id : $item->status_changed_by_id,
                'status_changed_at' => $status !== $oldStatus ? now() : $item->status_changed_at,
            ]);
            $this->recordUpdate($item, $ticket, $actor, $oldStatus, $changed);
            if (in_array($status, ServiceTicketWorkItem::TERMINAL_STATUSES, true)) {
                $this->completion->completeIfEligible($ticket, $actor);
            }

            return $item->refresh();
        });
    }

    public function transfer(ServiceTicketWorkItem $item, Organization $organization, User $actor, array $data): ServiceTicket
    {
        return DB::transaction(function () use ($item, $organization, $actor, $data): ServiceTicket {
            $item = ServiceTicketWorkItem::query()->lockForUpdate()->findOrFail($item->id);
            $ticket = $item->serviceTicket()->lockForUpdate()->firstOrFail();
            abort_unless((int) $organization->id === (int) $item->organization_id, 404);
            if ($item->follow_up_service_ticket_id) {
                return ServiceTicket::query()->forOrganization($organization->id)->findOrFail($item->follow_up_service_ticket_id);
            }
            $this->assertTicketWritable($ticket);
            if ($item->status !== 'needs_follow_up') {
                $this->reject('work_item', 'Only a Needs follow-up Work Item can create a follow-up Service Ticket.');
            }
            $this->assertNoActiveTimerForTerminalStatus($item, 'transferred');

            $description = collect([$item->detail, $item->work_note])->filter(fn ($value) => filled($value))->join("\n\n");
            $followUp = $this->ticketCreator->create($organization, $actor, [
                'customer_id' => $ticket->customer_id,
                'service_location_id' => $ticket->service_location_id,
                'contact_id' => $ticket->contact_id,
                'title' => $item->title,
                'description' => $description ?: null,
                'customer_visible_summary' => null,
                'priority' => $data['priority'],
                'source' => 'internal',
                'purpose' => $data['purpose'],
                'billing_disposition' => $data['billing_disposition'],
            ]);
            $item->update([
                'status' => 'transferred', 'follow_up_service_ticket_id' => $followUp->id, 'updated_by_id' => $actor->id,
                'status_changed_by_id' => $actor->id, 'status_changed_at' => now(),
            ]);
            $this->audit->record($organization, $actor, 'service_ticket.work_item_transferred', $ticket, [
                'ticket_id' => $ticket->id, 'work_item_id' => $item->id, 'old_status' => 'needs_follow_up',
                'new_status' => 'transferred', 'follow_up_ticket_id' => $followUp->id,
            ]);
            $this->completion->completeIfEligible($ticket, $actor);

            return $followUp;
        });
    }

    public function touch(ServiceTicketWorkItem $item, Visit $visit, User $actor): void
    {
        $now = now();
        $existing = DB::table('service_ticket_work_item_visit')
            ->where('service_ticket_work_item_id', $item->id)->where('visit_id', $visit->id)->lockForUpdate()->first();
        if ($existing) {
            DB::table('service_ticket_work_item_visit')->where('id', $existing->id)->update([
                'last_touched_by_id' => $actor->id, 'last_touched_at' => $now, 'updated_at' => $now,
            ]);

            return;
        }
        DB::table('service_ticket_work_item_visit')->insert([
            'organization_id' => $item->organization_id, 'service_ticket_work_item_id' => $item->id, 'visit_id' => $visit->id,
            'first_touched_by_id' => $actor->id, 'first_touched_at' => $now,
            'last_touched_by_id' => $actor->id, 'last_touched_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function assertNoActiveTimerForTerminalStatus(ServiceTicketWorkItem $item, string $status): void
    {
        if ($status !== 'open' && $item->timeEntries()->whereNotNull('active_user_id')->exists()) {
            $this->reject('status', "Switch or stop the active timer before changing this Work Item's status.");
        }
    }

    private function assertFieldWritable(Visit $visit, ServiceTicket $ticket): void
    {
        $this->assertTicketWritable($ticket);
        if ($visit->trashed() || ! in_array($visit->status, ['on_site', 'returned_for_correction'], true)) {
            $this->reject('visit', 'Work Items may be updated only while On Site or Returned for correction.');
        }
        if (! $visit->currentCloseout || $visit->currentCloseout->status !== 'draft') {
            $this->reject('visit', 'A current draft Closeout is required to update Work Items.');
        }
    }

    private function assertTicketWritable(ServiceTicket $ticket): void
    {
        if (! in_array($ticket->status, ['open', 'on_hold'], true)) {
            $this->reject('service_ticket', 'Completed or canceled Service Tickets are read-only.');
        }
    }

    private function assertSameContext(ServiceTicketWorkItem $item, Visit $visit, ServiceTicket $ticket): void
    {
        abort_unless((int) $item->organization_id === (int) $visit->organization_id
            && (int) $item->service_ticket_id === (int) $visit->service_ticket_id
            && (int) $ticket->id === (int) $item->service_ticket_id, 404);
    }

    private function recordUpdate(ServiceTicketWorkItem $item, ServiceTicket $ticket, User $actor, string $oldStatus, array $changed, ?int $visitId = null): void
    {
        $event = $oldStatus !== $item->status ? 'service_ticket.work_item_status_changed' : 'service_ticket.work_item_updated';
        $this->audit->record($ticket->organization, $actor, $event, $ticket, [
            'ticket_id' => $ticket->id, 'work_item_id' => $item->id, 'visit_id' => $visitId,
            'old_status' => $oldStatus, 'new_status' => $item->status, 'changed_fields' => $changed,
        ]);
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
