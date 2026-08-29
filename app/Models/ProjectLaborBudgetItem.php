<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'project_id', 'project_commercial_scope_id', 'source_revision_line_id', 'source_component_id', 'catalog_service_id', 'source_type', 'description', 'unit_name', 'quantity_millis', 'cost_cents', 'sell_cents', 'location_name', 'system_name', 'phase_name'])]
class ProjectLaborBudgetItem extends Model {}
