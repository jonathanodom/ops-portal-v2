<?php

namespace App\Domain;

use App\Models\User;
use App\Models\Visit;
use App\Models\VisitConfirmation;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VisitConfirmationWorkflow
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function confirm(Visit $visit, User $actor, string $method, ?string $note): VisitConfirmation
    {
        return DB::transaction(function () use ($visit, $actor, $method, $note): VisitConfirmation {
            $visit = Visit::query()->with('serviceTicket.organization')->lockForUpdate()->findOrFail($visit->id);

            if (! $visit->scheduled_start_at || ! $visit->scheduled_end_at) {
                throw ValidationException::withMessages(['method' => 'Schedule the Visit before recording confirmation.']);
            }
            if (! in_array($visit->status, ['scheduled', 'assigned'], true)) {
                throw ValidationException::withMessages(['method' => 'Confirmation can only be recorded before field execution begins.']);
            }

            $confirmation = VisitConfirmation::query()->create([
                'organization_id' => $visit->organization_id,
                'visit_id' => $visit->id,
                'schedule_version' => $visit->schedule_version,
                'method' => $method,
                'confirmed_by_id' => $actor->id,
                'confirmed_at' => now(),
                'note' => filled($note) ? $note : null,
                'scheduled_start_at' => $visit->scheduled_start_at,
                'scheduled_end_at' => $visit->scheduled_end_at,
            ]);

            $this->audit->record($visit->serviceTicket->organization, $actor, 'visit.confirmed', $visit, [
                'confirmation_id' => $confirmation->id,
                'method' => $method,
                'schedule_version' => $visit->schedule_version,
                'changed_fields' => ['confirmation'],
            ]);

            return $confirmation;
        });
    }
}
