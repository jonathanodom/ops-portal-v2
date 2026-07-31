<?php

namespace App\Models;

use Database\Factories\OrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'user_id', 'status'])]
class OrganizationMembership extends Model
{
    /** @use HasFactory<OrganizationMembershipFactory> */
    use HasFactory;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function capabilityOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Capability::class, 'organization_membership_capability')
            ->withPivot('effect')
            ->using(MembershipCapability::class);
    }

    public function visitAssignments(): HasMany
    {
        return $this->hasMany(VisitAssignment::class);
    }

    public function hasCapability(string $key): bool
    {
        $override = $this->capabilityOverrides()
            ->where('capabilities.key', $key)
            ->first();

        if ($override) {
            return $override->pivot->effect === 'grant';
        }

        return Capability::query()
            ->where('key', $key)
            ->whereHas('roles.memberships', fn ($query) => $query->whereKey($this->id))
            ->exists();
    }
}
