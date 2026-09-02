<?php

namespace App\Domain;

use App\Models\Closeout;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Str;

final class ReturnFollowUpCreator
{
    public function __construct(
        private readonly ServiceTicketCreator $tickets,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(Closeout $closeout, User $actor): ServiceTicket
    {
        $existing = ServiceTicket::query()
            ->where('organization_id', $closeout->organization_id)
            ->where('return_follow_up_source_closeout_id', $closeout->id)
            ->first();
        if ($existing) {
            return $existing;
        }

        $source = $closeout->visit->serviceTicket;
        $followUp = $this->tickets->create($source->organization, $actor, [
            'customer_id' => $source->customer_id,
            'service_location_id' => $source->service_location_id,
            'contact_id' => $source->contact_id,
            'title' => $this->title($source),
            'description' => $this->description($closeout),
            'customer_visible_summary' => null,
            'priority' => $source->priority,
            'source' => 'internal',
            'purpose' => $source->canonicalPurpose(),
            'billing_disposition' => $source->billing_disposition ?? 'billable',
            'return_follow_up_source_ticket_id' => $source->id,
            'return_follow_up_source_closeout_id' => $closeout->id,
            'return_follow_up_original_purpose' => $source->purpose,
            'return_follow_up_status' => ReturnFollowUpStatus::NEEDS_REVIEW,
        ]);

        $this->audit->record($source->organization, $actor, 'service_ticket.return_follow_up_created', $followUp, [
            'source_ticket_id' => $source->id,
            'source_closeout_id' => $closeout->id,
            'source_visit_id' => $closeout->visit_id,
            'purpose' => $followUp->purpose,
            'follow_up_status' => $followUp->return_follow_up_status,
        ]);

        return $followUp;
    }

    private function description(Closeout $closeout): string
    {
        return collect([
            'RETURN REASON' => $closeout->return_reason,
            'UNFINISHED WORK' => $closeout->unfinished_work,
            'NEEDED PARTS / EQUIPMENT' => $closeout->needed_equipment,
        ])->filter(fn (?string $value): bool => filled($value))
            ->map(fn (string $value, string $label): string => $label."\n".$value)
            ->implode("\n\n");
    }

    private function title(ServiceTicket $source): string
    {
        $title = preg_replace('/^(?:Return Visit —\s*)+/u', '', trim($source->title)) ?: 'Follow-Up';

        return Str::limit('Return Visit — '.$title, 255, '');
    }
}
