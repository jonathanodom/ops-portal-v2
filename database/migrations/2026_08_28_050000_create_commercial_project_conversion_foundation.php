<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_conversion_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });
        Schema::create('project_conversion_template_workstreams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_conversion_template_id');
            $table->foreign('project_conversion_template_id', 'conversion_workstream_template_fk')->references('id')->on('project_conversion_templates')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('project_conversion_template_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_conversion_template_id');
            $table->foreign('project_conversion_template_id', 'conversion_milestone_template_fk')->references('id')->on('project_conversion_templates')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('billing_milestone_sort_order')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('project_commercial_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_acceptance_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_conversion_template_id')->nullable();
            $table->foreign('project_conversion_template_id', 'commercial_scope_template_fk')->references('id')->on('project_conversion_templates')->nullOnDelete();
            $table->string('accepted_snapshot_hash', 64);
            $table->unsignedBigInteger('accepted_total_cents');
            $table->foreignId('converted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at');
            $table->timestamps();
            $table->unique('proposal_acceptance_id');
            $table->index(['organization_id', 'project_id']);
        });
        Schema::create('project_material_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_commercial_scope_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('source_revision_line_id');
            $table->unsignedBigInteger('source_component_id')->nullable();
            $table->foreignId('catalog_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 30);
            $table->string('description');
            $table->string('unit_name', 80)->nullable();
            $table->unsignedBigInteger('quantity_millis');
            $table->unsignedInteger('waste_basis_points')->default(0);
            $table->unsignedBigInteger('cost_cents')->nullable();
            $table->unsignedBigInteger('sell_cents')->nullable();
            $table->string('location_name')->nullable();
            $table->string('system_name')->nullable();
            $table->string('phase_name')->nullable();
            $table->timestamps();
            $table->unique(['project_commercial_scope_id', 'source_revision_line_id', 'source_component_id'], 'project_material_scope_source_unique');
        });
        Schema::create('project_labor_budget_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_commercial_scope_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('source_revision_line_id');
            $table->unsignedBigInteger('source_component_id')->nullable();
            $table->foreignId('catalog_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 30);
            $table->string('description');
            $table->string('unit_name', 80)->nullable();
            $table->unsignedBigInteger('quantity_millis');
            $table->unsignedBigInteger('cost_cents')->nullable();
            $table->unsignedBigInteger('sell_cents')->nullable();
            $table->string('location_name')->nullable();
            $table->string('system_name')->nullable();
            $table->string('phase_name')->nullable();
            $table->timestamps();
            $table->unique(['project_commercial_scope_id', 'source_revision_line_id', 'source_component_id'], 'project_labor_scope_source_unique');
        });
        Schema::create('project_billing_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_commercial_scope_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_milestone_id')->constrained()->restrictOnDelete();
            $table->foreignId('accepted_payment_milestone_id');
            $table->foreign('accepted_payment_milestone_id', 'project_billing_accepted_milestone_fk')->references('id')->on('accepted_payment_milestones')->restrictOnDelete();
            $table->timestamps();
            $table->unique('accepted_payment_milestone_id');
            $table->unique(['project_commercial_scope_id', 'project_milestone_id'], 'project_billing_scope_milestone_unique');
        });
        Schema::table('project_service_ticket', function (Blueprint $table): void {
            $table->foreignId('project_commercial_scope_id')->nullable()->after('project_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_service_ticket', fn (Blueprint $table) => $table->dropConstrainedForeignId('project_commercial_scope_id'));
        Schema::dropIfExists('project_billing_milestones');
        Schema::dropIfExists('project_labor_budget_items');
        Schema::dropIfExists('project_material_plan_items');
        Schema::dropIfExists('project_commercial_scopes');
        Schema::dropIfExists('project_conversion_template_milestones');
        Schema::dropIfExists('project_conversion_template_workstreams');
        Schema::dropIfExists('project_conversion_templates');
    }
};
