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

    private ?array $resolvedCapabilities = null;

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
        if ($this->resolvedCapabilities === null) {
            $this->loadMissing(['capabilityOverrides', 'roles.capabilities']);
            $resolved = [];
            foreach ($this->roles->flatMap->capabilities as $capability) {
                $resolved[$capability->key] = true;
            }
            foreach ($this->capabilityOverrides as $capability) {
                $resolved[$capability->key] = $capability->pivot->effect === 'grant';
            }
            $this->resolvedCapabilities = $resolved;
        }

        return $this->resolvedCapabilities[$key] ?? false;
    }
}
