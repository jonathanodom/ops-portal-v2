<?php

namespace App\Support;

use App\Models\OperationalIncident;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class IncidentRecorder
{
    private const SAFE_CONTEXT_KEYS = [
        'route', 'reason_code', 'invalid_fields', 'connection', 'job_class', 'ticket_id', 'visit_id',
        'closeout_id', 'handoff_id', 'invoice_id', 'attempt_id', 'transaction_id', 'receipt_id', 'provider', 'media_id', 'age_hours', 'status', 'state', 'driver', 'active_timer_count',
    ];

    public function record(
        ?Organization $organization,
        ?User $actor,
        string $category,
        string $severity = 'warning',
        ?Model $subject = null,
        array $context = [],
        ?string $requestId = null,
    ): ?OperationalIncident {
        $safeContext = array_intersect_key($context, array_flip(self::SAFE_CONTEXT_KEYS));
        if (isset($safeContext['invalid_fields'])) {
            $safeContext['invalid_fields'] = array_values(array_map('strval', (array) $safeContext['invalid_fields']));
        }

        $subjectType = $subject?->getMorphClass();
        $subjectId = $subject ? (string) $subject->getKey() : ($safeContext['job_class'] ?? null);
        $fingerprint = hash('sha256', implode('|', [
            $organization?->getKey() ?? 'global', $category, $subjectType ?? 'none', $subjectId ?? 'none',
            $safeContext['reason_code'] ?? $safeContext['state'] ?? 'none',
        ]));

        try {
            if (! Schema::hasTable('operational_incidents')) {
                return null;
            }

            return DB::transaction(function () use ($organization, $actor, $category, $severity, $subjectType, $subjectId, $requestId, $safeContext, $fingerprint): OperationalIncident {
                $incident = OperationalIncident::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
                if ($incident) {
                    $incident->update([
                        'severity' => $severity,
                        'actor_id' => $actor?->id,
                        'request_id' => $requestId,
                        'context' => $safeContext,
                        'status' => 'open',
                        'occurrences' => $incident->occurrences + 1,
                        'last_occurred_at' => now(),
                        'resolved_by_id' => null,
                        'resolved_at' => null,
                    ]);

                    return $incident;
                }

                return OperationalIncident::query()->create([
                    'organization_id' => $organization?->id,
                    'category' => $category,
                    'severity' => $severity,
                    'fingerprint' => $fingerprint,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'actor_id' => $actor?->id,
                    'request_id' => $requestId,
                    'context' => $safeContext,
                    'status' => 'open',
                    'occurrences' => 1,
                    'first_occurred_at' => now(),
                    'last_occurred_at' => now(),
                ]);
            });
        } catch (Throwable) {
            Log::warning('Operational incident persistence failed.', [
                'category' => $category,
                'severity' => $severity,
                'request_id' => $requestId,
            ]);

            return null;
        }
    }
}
