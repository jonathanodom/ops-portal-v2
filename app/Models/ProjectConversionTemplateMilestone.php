<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['project_conversion_template_id', 'name', 'description', 'billing_milestone_sort_order', 'sort_order'])]
class ProjectConversionTemplateMilestone extends Model {}
