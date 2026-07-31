<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['key', 'name', 'description'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(Capability::class);
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationMembership::class);
    }
}
