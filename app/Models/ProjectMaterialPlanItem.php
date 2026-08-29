<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'project_id', 'project_commercial_scope_id', 'source_revision_line_id', 'source_component_id', 'catalog_product_id', 'source_type', 'change_effect', 'description', 'unit_name', 'quantity_millis', 'delta_quantity_millis', 'waste_basis_points', 'cost_cents', 'delta_cost_cents', 'sell_cents', 'delta_sell_cents', 'location_name', 'system_name', 'phase_name'])]
class ProjectMaterialPlanItem extends Model {}
