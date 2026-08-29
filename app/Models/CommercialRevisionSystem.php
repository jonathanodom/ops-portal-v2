<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'commercial_revision_id', 'source_default_id', 'name', 'sort_order'])]
class CommercialRevisionSystem extends Model {}
