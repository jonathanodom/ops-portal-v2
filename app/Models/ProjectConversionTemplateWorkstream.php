<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['project_conversion_template_id', 'name', 'description', 'sort_order'])]
class ProjectConversionTemplateWorkstream extends Model {}
