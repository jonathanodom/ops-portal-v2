<?php

namespace App\Domain;

use App\Events\BillingHandoffCreated;
use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\CloseoutReview;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;

class ServiceTicketCompletion
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function completeIfEligible(ServiceTicket $ticket, User $actor, ?Closeout $closeout = null, ?CloseoutReview $review = null): ?BillingHandoff
    {
        return DB::transaction(function () use ($ticket, $actor, $closeout, $review): ?BillingHandoff {
            $ticket = ServiceTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($ticket->status === 'completed') {
                return $ticket->billingHandoff;
            }
            if (! in_array($ticket->status, ['open', 'on_hold'], true) || $ticket->visits()->whereNotIn('status', ['approved', 'canceled', 'customer_unavailable'])->exists()) {
                return null;
            }

            $closeout = $this->resolvedCloseout($ticket, $closeout);
            if (! $closeout) {
                return null;
            }
            $visit = Visit::query()->where('service_ticket_id', $ticket->id)->where('status', 'approved')->find($closeout->visit_id);
            if (! $visit) {
                return null;
            }
            $review = $review && (int) $review->closeout_id === (int) $closeout->id && $review->decision === 'approved'
                ? $review
                : $closeout->reviews()->where('decision', 'approved')->latest('decided_at')->first();
            if (! $review) {
                return null;
            }

            $ticket->update([
                'status' => 'completed',
                'status_reason' => null,
                'status_changed_at' => now(),
                'status_changed_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);
            if ($ticket->billing_disposition !== 'billable') {
                $this->audit->record($ticket->organization, $actor, 'service_ticket.completed_non_billable', $ticket, [
                    'ticket_id' => $ticket->id,
                    'visit_id' => $visit->id,
                    'closeout_id' => $closeout->id,
                    'purpose' => $ticket->purpose,
                    'billing_disposition' => $ticket->billing_disposition,
                ]);

                return null;
            }
            $handoff = BillingHandoff::query()->firstOrCreate(
                ['service_ticket_id' => $ticket->id],
                [
                    'organization_id' => $ticket->organization_id,
                    'visit_id' => $visit->id,
                    'closeout_id' => $closeout->id,
                    'status' => 'ready',
                    'approved_time_minutes' => $this->approvedTimeMinutes($review, $visit),
                    'approved_parts_count' => $this->approvedPartsCount($review, $closeout),
                    'created_by_id' => $actor->id,
                ],
            );
            if ($handoff->wasRecentlyCreated) {
                BillingHandoffCreated::dispatch($handoff);
                $this->audit->record($ticket->organization, $actor, 'billing_handoff.created', $handoff, [
                    'ticket_id' => $ticket->id,
                    'visit_id' => $visit->id,
                    'closeout_id' => $closeout->id,
                ]);
            }
            $this->audit->record($ticket->organization, $actor, 'service_ticket.completed', $ticket, [
                'ticket_id' => $ticket->id,
                'visit_id' => $visit->id,
                'closeout_id' => $closeout->id,
            ]);

            return $handoff;
        });
    }

    private function resolvedCloseout(ServiceTicket $ticket, ?Closeout $preferred): ?Closeout
    {
        $query = Closeout::query()
            ->where('organization_id', $ticket->organization_id)
            ->where('status', 'submitted')
            ->where('outcome', 'resolved')
            ->whereHas('visit', fn ($visit) => $visit->where('service_ticket_id', $ticket->id)->where('status', 'approved'))
            ->whereHas('reviews', fn ($review) => $review->where('decision', 'approved'));

        if ($preferred && (int) $preferred->visit?->service_ticket_id === (int) $ticket->id) {
            return (clone $query)->whereKey($preferred->id)->first();
        }

        return $query->latest('submitted_at')->latest('id')->first();
    }

    private function approvedTimeMinutes(CloseoutReview $review, Visit $visit): int
    {
        $adjustments = $review->adjustments()->where('type', 'time')->get()->keyBy('visit_time_entry_id');

        return (int) $visit->timeEntries()->get()->sum(function ($entry) use ($adjustments): int {
            $adjustment = $adjustments->get($entry->id);
            if ($adjustment?->excluded || ! $entry->ended_at) {
                return 0;
            }

            return $adjustment?->approved_minutes ?? (int) ceil($entry->started_at->diffInSeconds($entry->ended_at) / 60);
        });
    }

    private function approvedPartsCount(CloseoutReview $review, Closeout $closeout): int
    {
        $adjustments = $review->adjustments()->where('type', 'part')->get()->keyBy('visit_part_proposal_id');

        return $closeout->parts()->whereNull('removed_at')->get()
            ->reject(fn ($part) => $adjustments->get($part->id)?->excluded)
            ->count();
    }
}
