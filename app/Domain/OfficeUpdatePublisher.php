<?php

namespace App\Domain;

use App\Domain\Notifications\OfficeUpdateNotifier;
use App\Models\OfficeUpdate;
use App\Models\OfficeUpdateRecipient;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class OfficeUpdatePublisher
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly OfficeUpdateNotifier $notifications,
    ) {}

    /** @param array<string, mixed> $data */
    public function publish(Organization $organization, User $actor, array $data): OfficeUpdate
    {
        $audienceType = (string) $data['audience_type'];
        $requestedUserIds = collect($data['recipient_user_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $recipientUserIds = $audienceType === 'all_staff'
            ? $this->eligibleStaff($organization)->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()
            : $this->selectedStaff($organization, $requestedUserIds->all());
        if ($recipientUserIds->isEmpty()) {
            throw ValidationException::withMessages(['recipient_user_ids' => 'Select at least one eligible staff member.']);
        }

        $requestHash = hash('sha256', json_encode([
            'title' => trim((string) $data['title']),
            'body' => trim((string) $data['body']),
            'audience_type' => $audienceType,
            'recipient_user_ids' => $audienceType === 'selected_staff' ? $requestedUserIds->all() : [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $tokenHash = hash('sha256', (string) $data['publish_token']);

        return DB::transaction(function () use ($organization, $actor, $data, $audienceType, $requestedUserIds, $recipientUserIds, $requestHash, $tokenHash): OfficeUpdate {
            $update = OfficeUpdate::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'publish_token_hash' => $tokenHash],
                [
                    'title' => trim((string) $data['title']),
                    'body' => trim((string) $data['body']),
                    'audience_type' => $audienceType,
                    'audience_snapshot' => [
                        'type' => $audienceType,
                        'selected_user_ids' => $audienceType === 'selected_staff' ? $requestedUserIds->all() : [],
                        'resolved_user_ids' => $recipientUserIds->all(),
                    ],
                    'recipient_count' => $recipientUserIds->count(),
                    'published_by_id' => $actor->id,
                    'published_at' => now(),
                    'request_sha256' => $requestHash,
                ],
            );
            if (! hash_equals($update->request_sha256, $requestHash)) {
                throw ValidationException::withMessages(['publish_token' => 'This publish request token was already used for different content. Refresh and try again.']);
            }
            if (! $update->wasRecentlyCreated) {
                return $update;
            }

            foreach ($recipientUserIds as $userId) {
                OfficeUpdateRecipient::query()->create([
                    'organization_id' => $organization->id,
                    'office_update_id' => $update->id,
                    'user_id' => $userId,
                ]);
            }
            $this->audit->record($organization, $actor, 'office_update.published', $update, [
                'office_update_id' => $update->id,
                'audience_type' => $audienceType,
                'recipient_count' => $recipientUserIds->count(),
            ]);
            DB::afterCommit(function () use ($update, $recipientUserIds): void {
                try {
                    $this->notifications->notify($update, $recipientUserIds->all());
                } catch (Throwable $exception) {
                    Log::error('Office Update notification publication failed.', [
                        'organization_id' => $update->organization_id,
                        'office_update_id' => $update->id,
                        'failure_type' => class_basename($exception),
                    ]);
                }
            });

            return $update;
        });
    }

    private function eligibleStaff(Organization $organization)
    {
        return OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->whereDoesntHave('roles', fn ($query) => $query->where('key', 'jarvis_service'))
            ->orderBy('user_id')
            ->get();
    }

    /** @param list<int> $userIds */
    private function selectedStaff(Organization $organization, array $userIds)
    {
        $eligible = $this->eligibleStaff($organization)->whereIn('user_id', $userIds)->pluck('user_id')->map(fn ($id): int => (int) $id)->sort()->values();
        if ($eligible->all() !== $userIds) {
            throw ValidationException::withMessages(['recipient_user_ids' => 'Every selected recipient must be active staff in this organization.']);
        }

        return $eligible;
    }
}
