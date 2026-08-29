<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_documents', function (Blueprint $table): void {
            $table->foreignId('baseline_project_commercial_scope_id')->nullable()->after('project_id');
            $table->foreign('baseline_project_commercial_scope_id', 'commercial_doc_baseline_scope_fk')->references('id')->on('project_commercial_scopes')->restrictOnDelete();
            $table->index(['organization_id', 'project_id', 'document_type'], 'commercial_doc_project_type_idx');
        });
        Schema::table('commercial_revision_lines', function (Blueprint $table): void {
            $table->string('change_effect', 20)->default('add')->after('line_type');
            $table->string('substitution_group', 64)->nullable()->after('change_effect');
        });
        Schema::table('commercial_revisions', function (Blueprint $table): void {
            $table->bigInteger('change_order_delta_cents')->default(0)->after('total_cents');
            $table->unsignedBigInteger('resulting_project_total_cents')->default(0)->after('change_order_delta_cents');
        });
        Schema::table('proposal_publications', function (Blueprint $table): void {
            $table->bigInteger('change_order_delta_cents')->default(0)->after('total_cents');
            $table->unsignedBigInteger('resulting_project_total_cents')->default(0)->after('change_order_delta_cents');
        });
        Schema::table('proposal_acceptances', function (Blueprint $table): void {
            $table->bigInteger('change_order_delta_cents')->default(0)->after('total_cents');
            $table->unsignedBigInteger('resulting_project_total_cents')->default(0)->after('change_order_delta_cents');
        });
        Schema::table('project_commercial_scopes', function (Blueprint $table): void {
            $table->string('scope_type', 20)->default('baseline')->after('project_id');
            $table->foreignId('parent_scope_id')->nullable()->after('scope_type');
            $table->foreign('parent_scope_id', 'project_scope_parent_fk')->references('id')->on('project_commercial_scopes')->restrictOnDelete();
            $table->bigInteger('contract_delta_cents')->default(0)->after('accepted_total_cents');
            $table->unsignedBigInteger('resulting_contract_total_cents')->default(0)->after('contract_delta_cents');
            $table->foreignId('reviewed_by_id')->nullable()->after('converted_by_id')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_id');
        });
        Schema::table('project_material_plan_items', function (Blueprint $table): void {
            $table->string('change_effect', 20)->default('baseline')->after('source_type');
            $table->bigInteger('delta_quantity_millis')->default(0)->after('quantity_millis');
            $table->bigInteger('delta_cost_cents')->nullable()->after('cost_cents');
            $table->bigInteger('delta_sell_cents')->nullable()->after('sell_cents');
        });
        Schema::table('project_labor_budget_items', function (Blueprint $table): void {
            $table->string('change_effect', 20)->default('baseline')->after('source_type');
            $table->bigInteger('delta_quantity_millis')->default(0)->after('quantity_millis');
            $table->bigInteger('delta_cost_cents')->nullable()->after('cost_cents');
            $table->bigInteger('delta_sell_cents')->nullable()->after('sell_cents');
        });
        Schema::table('project_billing_milestones', function (Blueprint $table): void {
            $table->bigInteger('contract_delta_cents')->default(0)->after('accepted_payment_milestone_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_billing_milestones', fn (Blueprint $table) => $table->dropColumn('contract_delta_cents'));
        Schema::table('project_labor_budget_items', fn (Blueprint $table) => $table->dropColumn(['change_effect', 'delta_quantity_millis', 'delta_cost_cents', 'delta_sell_cents']));
        Schema::table('project_material_plan_items', fn (Blueprint $table) => $table->dropColumn(['change_effect', 'delta_quantity_millis', 'delta_cost_cents', 'delta_sell_cents']));
        Schema::table('project_commercial_scopes', function (Blueprint $table): void {
            $table->dropForeign('project_scope_parent_fk');
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropColumn(['scope_type', 'parent_scope_id', 'contract_delta_cents', 'resulting_contract_total_cents', 'reviewed_at']);
        });
        Schema::table('proposal_acceptances', fn (Blueprint $table) => $table->dropColumn(['change_order_delta_cents', 'resulting_project_total_cents']));
        Schema::table('proposal_publications', fn (Blueprint $table) => $table->dropColumn(['change_order_delta_cents', 'resulting_project_total_cents']));
        Schema::table('commercial_revisions', fn (Blueprint $table) => $table->dropColumn(['change_order_delta_cents', 'resulting_project_total_cents']));
        Schema::table('commercial_revision_lines', fn (Blueprint $table) => $table->dropColumn(['change_effect', 'substitution_group']));
        Schema::table('commercial_documents', function (Blueprint $table): void {
            $table->dropIndex('commercial_doc_project_type_idx');
            $table->dropForeign('commercial_doc_baseline_scope_fk');
            $table->dropColumn('baseline_project_commercial_scope_id');
        });
    }
};
