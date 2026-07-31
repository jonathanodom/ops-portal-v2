<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditRecorder
{
    /** @param array<string, mixed> $metadata */
    public function record(Organization $organization, ?User $actor, string $eventType, Model $subject, array $metadata = []): AuditEvent
    {
        return AuditEvent::query()->create([
            'organization_id' => $organization->id,
            'actor_id' => $actor?->id,
            'event_type' => $eventType,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }
}
