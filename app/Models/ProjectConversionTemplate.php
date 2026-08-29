<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'name', 'active'])]
class ProjectConversionTemplate extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function workstreams(): HasMany
    {
        return $this->hasMany(ProjectConversionTemplateWorkstream::class)->orderBy('sort_order');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectConversionTemplateMilestone::class)->orderBy('sort_order');
    }
}
