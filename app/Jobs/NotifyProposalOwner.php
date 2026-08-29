<?php

namespace App\Jobs;

use App\Models\ProposalEngagementEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class NotifyProposalOwner implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $eventId) {}

    public function handle(): void
    {
        $event = ProposalEngagementEvent::query()->with('publication.revision.document.opportunity.owner')->findOrFail($this->eventId);
        if ($event->owner_notified_at) {
            return;
        }
        $owner = $event->publication->revision->document->opportunity->owner;
        if ($owner?->email) {
            Mail::raw('Proposal '.$event->publication->snapshot['document']['number'].' recorded '.str_replace('_', ' ', $event->event_type).'.', function ($message) use ($owner): void {
                $message->to($owner->email)->subject('Proposal activity');
            });
        }
        $event->update(['owner_notified_at' => now()]);
    }
}
