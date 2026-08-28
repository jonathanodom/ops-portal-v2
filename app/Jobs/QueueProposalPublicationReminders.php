<?php

namespace App\Jobs;

use App\Models\OrganizationCommercialSetting;
use App\Models\ProposalDeliveryAttempt;
use App\Models\ProposalPublication;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class QueueProposalPublicationReminders implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        ProposalPublication::query()
            ->where('status', 'active')
            ->with(['revision.document.opportunity.organization', 'recipients'])
            ->chunkById(100, function ($publications): void {
                foreach ($publications as $publication) {
                    $organization = $publication->revision->document->opportunity->organization;
                    $settings = OrganizationCommercialSetting::query()
                        ->where('organization_id', $publication->organization_id)
                        ->first();

                    if (! $settings) {
                        continue;
                    }

                    $today = CarbonImmutable::now($organization->timezone)->startOfDay();
                    $expiresOn = CarbonImmutable::instance($publication->expires_at)
                        ->setTimezone($organization->timezone)
                        ->startOfDay();
                    $daysUntilExpiry = (int) $today->diffInDays($expiresOn, false);

                    if (! in_array($daysUntilExpiry, [(int) $settings->first_reminder_days, (int) $settings->second_reminder_days], true)) {
                        continue;
                    }

                    foreach ($publication->recipients->whereNull('revoked_at') as $recipient) {
                        $attempt = ProposalDeliveryAttempt::query()->firstOrCreate(
                            [
                                'proposal_publication_id' => $publication->id,
                                'idempotency_key' => 'reminder-'.$daysUntilExpiry.'-'.$expiresOn->toDateString().'-recipient-'.$recipient->id,
                            ],
                            [
                                'organization_id' => $publication->organization_id,
                                'proposal_recipient_id' => $recipient->id,
                                'delivery_type' => 'reminder',
                                'status' => 'queued',
                            ],
                        );

                        if ($attempt->wasRecentlyCreated) {
                            DeliverProposalPublication::dispatch($attempt->id);
                        }
                    }
                }
            });
    }
}
