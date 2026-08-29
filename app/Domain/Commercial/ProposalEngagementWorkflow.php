<?php

namespace App\Domain\Commercial;

use App\Jobs\NotifyProposalOwner;
use App\Models\ProposalComment;
use App\Models\ProposalEngagementEvent;
use App\Models\ProposalOptionSelection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProposalEngagementWorkflow
{
    public function recordView(ProposalAccess $access, Request $request): ProposalEngagementEvent
    {
        return DB::transaction(function () use ($access, $request): ProposalEngagementEvent {
            $publication = $access->publication->newQuery()->whereKey($access->publication->id)->lockForUpdate()->firstOrFail();
            $first = $publication->first_viewed_at === null;
            if ($first) {
                $publication->update(['first_viewed_at' => now()]);
            }
            $holder = $access->recipient ?? $access->shareLink;
            if ($holder) {
                $holder->newQuery()->whereKey($holder->id)->update(['first_viewed_at' => $holder->first_viewed_at ?? now(), 'last_viewed_at' => now()]);
            }
            $event = $this->event($access, $first ? 'first_view' : 'view', $request);
            NotifyProposalOwner::dispatch($event->id)->afterCommit();

            return $event;
        });
    }

    /** @param array<int,bool> $selections */
    public function saveOptions(ProposalAccess $access, array $selections): void
    {
        $this->assertActionable($access);
        $optionalIds = collect($access->publication->snapshot['lines'])->where('optional', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($selections as $lineId => $included) {
            if (! in_array((int) $lineId, $optionalIds, true)) {
                throw ValidationException::withMessages(['options' => 'An option does not belong to this Proposal.']);
            }
            ProposalOptionSelection::query()->updateOrCreate(
                ['proposal_publication_id' => $access->publication->id, 'proposal_recipient_id' => $access->recipientId(), 'proposal_share_link_id' => $access->shareLinkId(), 'publication_line_id' => (int) $lineId],
                ['organization_id' => $access->publication->organization_id, 'included' => (bool) $included],
            );
        }
    }

    /** @return array<int,bool> */
    public function selections(ProposalAccess $access): array
    {
        return ProposalOptionSelection::query()
            ->where('proposal_publication_id', $access->publication->id)
            ->where('proposal_recipient_id', $access->recipientId())
            ->where('proposal_share_link_id', $access->shareLinkId())
            ->pluck('included', 'publication_line_id')
            ->map(fn ($value) => (bool) $value)
            ->all();
    }

    public function comment(ProposalAccess $access, Request $request, array $data): ProposalComment
    {
        $this->assertActionable($access);
        $this->validateTarget($access, $data['target_type'], $data['target_reference'] ?? null);

        return DB::transaction(function () use ($access, $request, $data): ProposalComment {
            $comment = ProposalComment::query()->create([
                'organization_id' => $access->publication->organization_id, 'proposal_publication_id' => $access->publication->id,
                'proposal_recipient_id' => $access->recipientId(), 'proposal_share_link_id' => $access->shareLinkId(),
                'author_type' => 'customer', 'author_name' => $data['name'], 'author_email' => $data['email'] ?? null,
                'target_type' => $data['target_type'], 'target_reference' => $data['target_reference'] ?? null, 'body' => $data['body'],
            ]);
            $event = $this->event($access, 'comment', $request, $data['target_type'], $data['target_reference'] ?? null, ['comment_id' => $comment->id]);
            NotifyProposalOwner::dispatch($event->id)->afterCommit();

            return $comment;
        });
    }

    /** @param array<string,mixed> $safeMetadata */
    public function event(ProposalAccess $access, string $type, Request $request, ?string $targetType = null, ?string $targetReference = null, array $safeMetadata = []): ProposalEngagementEvent
    {
        $ip = $request->ip();

        return ProposalEngagementEvent::query()->create([
            'organization_id' => $access->publication->organization_id, 'proposal_publication_id' => $access->publication->id,
            'proposal_recipient_id' => $access->recipientId(), 'proposal_share_link_id' => $access->shareLinkId(),
            'event_type' => $type, 'target_type' => $targetType, 'target_reference' => $targetReference,
            'encrypted_ip' => $ip, 'ip_hash' => $ip ? hash('sha256', strtolower(trim($ip))) : null,
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''), 'safe_metadata' => $safeMetadata ?: null, 'occurred_at' => now(),
        ]);
    }

    public function assertActionable(ProposalAccess $access): void
    {
        $publication = $access->publication;
        if ($publication->status === 'active' && $publication->expires_at->isPast()) {
            $publication->update(['status' => 'expired']);
        }
        if ($publication->status !== 'active') {
            throw ValidationException::withMessages(['proposal' => 'This Proposal is view-only and no longer accepts responses.']);
        }
    }

    private function validateTarget(ProposalAccess $access, string $type, ?string $reference): void
    {
        if ($type === 'proposal') {
            return;
        }
        $collection = $type === 'section' ? ($access->publication->snapshot['sections'] ?? []) : ($access->publication->snapshot['lines'] ?? []);
        $exists = collect($collection)->contains(fn ($item) => (string) ($item['id'] ?? '') === (string) $reference);
        if (! $exists) {
            throw ValidationException::withMessages(['target_reference' => 'The selected Proposal item is unavailable.']);
        }
    }
}
