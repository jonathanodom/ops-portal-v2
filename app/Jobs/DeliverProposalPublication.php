<?php

namespace App\Jobs;

use App\Domain\Commercial\CommercialOpportunityAutomation;
use App\Models\ProposalDeliveryAttempt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class DeliverProposalPublication implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $attemptId) {}

    public function handle(CommercialOpportunityAutomation $automation): void
    {
        $attempt = ProposalDeliveryAttempt::query()->with(['publication.revision.document', 'recipient'])->findOrFail($this->attemptId);
        if ($attempt->status === 'sent') {
            return;
        }

        $attempt->update(['status' => 'processing', 'attempted_at' => now(), 'safe_failure_code' => null]);
        try {
            $token = Str::random(80);
            $attempt->recipient->update(['token_hash' => hash('sha256', $token)]);
            $url = route('proposals.show', $token);
            Mail::raw("A Proposal has been prepared for you by NewDay Tech. Review it securely at: {$url}", function ($message) use ($attempt): void {
                $message->to($attempt->recipient->email)->subject($attempt->publication->revision->document->document_number.' Proposal delivery test');
            });
            $attempt->update(['status' => 'sent', 'completed_at' => now()]);
            $automation->presented($attempt->publication->revision->document->opportunity, $attempt->publication->publishedBy ?? null, $attempt->publication->id);
        } catch (Throwable $exception) {
            $attempt->update(['status' => 'failed', 'safe_failure_code' => class_basename($exception), 'completed_at' => now()]);
            throw $exception;
        }
    }
}
