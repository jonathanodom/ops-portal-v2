<?php

namespace App\Models;

use App\Domain\VisitCreator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'service_ticket_id', 'ticket_visit_number', 'service_location_id', 'return_of_visit_id', 'current_closeout_id',
    'status', 'timezone', 'scheduled_start_at', 'scheduled_end_at', 'en_route_at',
    'en_route_by_id', 'on_site_at', 'on_site_by_id', 'canceled_at', 'canceled_by_id',
    'cancellation_reason', 'return_reason', 'scheduled_by_id', 'created_by_id', 'updated_by_id',
    'archived_by_id', 'archive_reason', 'restored_by_id', 'restored_at',
])]
class Visit extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Visit $visit): void {
            if (! $visit->ticket_visit_number) {
                app(VisitCreator::class)->assignNumber($visit);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'en_route_at' => 'datetime',
            'on_site_at' => 'datetime',
            'canceled_at' => 'datetime',
            'restored_at' => 'datetime',
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
        return $this->belongsTo(self::class, 'return_of_visit_id')->withTrashed();
    }

    public function returnVisits(): HasMany
    {
        return $this->hasMany(self::class, 'return_of_visit_id');
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

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_id');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by_id');
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

    public function displayNumber(): string
    {
        return 'Visit '.$this->ticket_visit_number;
    }

    public function displayLabel(): string
    {
        $label = $this->displayNumber();
        if ($this->return_of_visit_id) {
            $sourceNumber = $this->relationLoaded('returnOfVisit')
                ? $this->returnOfVisit?->ticket_visit_number
                : $this->returnOfVisit()->value('ticket_visit_number');
            if ($sourceNumber) {
                $label .= ' · Return of Visit '.$sourceNumber;
            }
        }

        return $label;
    }
}
