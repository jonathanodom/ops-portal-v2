<?php

namespace App\Domain\ServiceTickets\Documents;

use App\Domain\WorkItemTimeAttribution;
use App\Models\Closeout;
use App\Models\Organization;
use App\Models\ServiceTicketWorkItem;
use App\Models\Visit;
use App\Models\VisitTimeEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class ServiceTicketDocumentSupport
{
    public function __construct(private readonly WorkItemTimeAttribution $attribution) {}

    public function generatedAt(Organization $organization): CarbonInterface
    {
        return now($organization->timezone);
    }

    public function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0 min';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = (int) ceil(($seconds % 3600) / 60);

        return trim(($hours ? $hours.' hr ' : '').($minutes ? $minutes.' min' : ''));
    }

    /** @return array{start:?CarbonInterface,end:?CarbonInterface,duration_seconds:int} */
    public function siteWindow(Collection $entries): array
    {
        $onSite = $entries->where('category', 'on_site')->filter(fn (VisitTimeEntry $entry) => $entry->effective_ended_at);
        if ($onSite->isEmpty()) {
            return ['start' => null, 'end' => null, 'duration_seconds' => 0];
        }
        $start = $onSite->min(fn (VisitTimeEntry $entry) => $entry->effective_started_at->getTimestamp());
        $end = $onSite->max(fn (VisitTimeEntry $entry) => $entry->effective_ended_at->getTimestamp());
        $first = $onSite->first()->effective_started_at->copy()->setTimestamp($start);
        $last = $onSite->first()->effective_ended_at->copy()->setTimestamp($end);

        return ['start' => $first, 'end' => $last, 'duration_seconds' => max(0, $end - $start)];
    }

    /** @return array<string, mixed> */
    public function acknowledgment(?Closeout $closeout, ?int $serviceTicketId = null): array
    {
        if (! $closeout) {
            return ['type' => 'none'];
        }
        if ($closeout->acknowledgmentSignature) {
            $signature = $closeout->acknowledgmentSignature;

            return [
                'type' => 'signed', 'name' => $signature->signer_name, 'role' => $signature->signer_role,
                'signed_at' => $signature->signed_at, 'statement' => $signature->statement_snapshot,
                'statement_version' => $signature->statement_version,
                'image_url' => $serviceTicketId
                    ? route('office.service-tickets.documents.signature', [$serviceTicketId, $signature])
                    : route('closeout-acknowledgment-signatures.show', $signature),
            ];
        }
        if ($closeout->ack_unavailable_category) {
            return [
                'type' => 'fallback', 'name' => $closeout->representative_name, 'role' => $closeout->representative_role,
                'category' => config('field_execution.ack_fallbacks.'.$closeout->ack_unavailable_category, str($closeout->ack_unavailable_category)->headline()),
                'detail' => $closeout->ack_unavailable_detail, 'recorded_at' => $closeout->acknowledged_at,
            ];
        }

        return ['type' => 'none'];
    }

    /** @return array<string, mixed> */
    public function workItem(ServiceTicketWorkItem $item, bool $includeInternal = false): array
    {
        return [
            'title' => $item->title, 'status' => $item->status,
            'origin' => $item->origin, 'detail' => $includeInternal ? $item->detail : null,
            'work_note' => $includeInternal ? $item->work_note : null,
            'discovered_visit' => $item->discoveredVisit?->displayNumber(),
            'handled_visits' => $item->visits->sortBy('ticket_visit_number')->map->displayNumber()->values()->all(),
            'follow_up_ticket' => $item->followUpServiceTicket?->ticket_number,
        ];
    }

    /** @return array<string, mixed> */
    public function detailedEntry(VisitTimeEntry $entry, string $timezone, ?Collection $adjustments = null): array
    {
        $projection = $this->attribution->forEntry($entry);
        $adjustment = $adjustments?->firstWhere('visit_time_entry_id', $entry->id);

        return [
            'id' => $entry->id, 'technician' => $entry->user->name,
            'category' => $entry->category, 'category_label' => $entry->category === 'travel' ? 'En route' : str($entry->category)->headline()->toString(),
            'started_at' => $entry->effective_started_at?->copy()->timezone($timezone),
            'ended_at' => $entry->effective_ended_at?->copy()->timezone($timezone),
            'recorded_started_at' => $entry->started_at?->copy()->timezone($timezone),
            'recorded_ended_at' => $entry->ended_at?->copy()->timezone($timezone),
            'duration_seconds' => $entry->effective_ended_at ? $entry->effectiveDurationSeconds() : null,
            'duration_label' => $entry->effective_ended_at ? $this->duration($entry->effectiveDurationSeconds()) : 'In progress',
            'corrected' => $entry->hasSubmittedCorrection(), 'correction_count' => $entry->corrections->count(),
            'captured_focus' => $entry->workItem?->title ?? 'Primary Ticket scope',
            'allocation' => $projection,
            'review' => $adjustment ? ['excluded' => (bool) $adjustment->excluded, 'approved_minutes' => $adjustment->approved_minutes] : null,
        ];
    }

    /** @return array<string, int> */
    public function categoryTotals(Collection $entries): array
    {
        return collect(['travel', 'on_site', 'other'])->mapWithKeys(fn (string $category) => [
            $category => (int) $entries->where('category', $category)->sum(fn (VisitTimeEntry $entry) => $entry->effective_ended_at ? $entry->effectiveDurationSeconds() : 0),
        ])->all();
    }

    public function visitState(Visit $visit): string
    {
        return match ($visit->status) {
            'approved' => 'Completed',
            'pending_closeout' => 'Awaiting review',
            'returned_for_correction' => 'Follow-up pending',
            default => str($visit->status)->headline()->toString(),
        };
    }
}
