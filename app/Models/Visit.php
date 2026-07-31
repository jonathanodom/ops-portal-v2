<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'service_ticket_id', 'service_location_id', 'return_of_visit_id', 'current_closeout_id',
    'status', 'timezone', 'scheduled_start_at', 'scheduled_end_at', 'en_route_at',
    'en_route_by_id', 'on_site_at', 'on_site_by_id', 'canceled_at', 'canceled_by_id',
    'cancellation_reason', 'return_reason', 'scheduled_by_id', 'created_by_id', 'updated_by_id',
])]
class Visit extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'en_route_at' => 'datetime',
            'on_site_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function serviceTicket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class);
    }

    public function serviceLocation(): BelongsTo
    {
        return $this->belongsTo(ServiceLocation::class);
    }

    public function returnOfVisit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'return_of_visit_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(VisitAssignment::class);
    }

    public function currentCloseout(): BelongsTo
    {
        return $this->belongsTo(Closeout::class, 'current_closeout_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(VisitTimeEntry::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scheduledStartLocal(): ?CarbonInterface
    {
        return $this->scheduled_start_at?->copy()->timezone($this->timezone);
    }

    public function scheduledEndLocal(): ?CarbonInterface
    {
        return $this->scheduled_end_at?->copy()->timezone($this->timezone);
    }
}
