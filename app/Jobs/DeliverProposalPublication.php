<?php

namespace App\Jobs;

use App\Models\ProposalDeliveryAttempt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverProposalPublication implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $attemptId) {}

    public function handle(): void
    {
        $attempt = ProposalDeliveryAttempt::query()->with(['publication.revision.document', 'recipient'])->findOrFail($this->attemptId);
        if ($attempt->status === 'sent') {
            return;
        }

        if (! app()->environment(['local', 'testing'])) {
            $attempt->update([
                'status' => 'failed',
                'attempted_at' => now(),
                'completed_at' => now(),
                'safe_failure_code' => 'phase4_customer_delivery_disabled',
            ]);

            return;
        }

        $attempt->update(['status' => 'processing', 'attempted_at' => now(), 'safe_failure_code' => null]);
        try {
            Mail::raw('A Proposal has been prepared in NewDay Tech Ops Portal. Customer access will be enabled after the Phase 5 acceptance gate.', function ($message) use ($attempt): void {
                $message->to($attempt->recipient->email)->subject($attempt->publication->revision->document->document_number.' Proposal delivery test');
            });
            $attempt->update(['status' => 'sent', 'completed_at' => now()]);
        } catch (Throwable $exception) {
            $attempt->update(['status' => 'failed', 'safe_failure_code' => class_basename($exception), 'completed_at' => now()]);
            throw $exception;
        }
    }
}
