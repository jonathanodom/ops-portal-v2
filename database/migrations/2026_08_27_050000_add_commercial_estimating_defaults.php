<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_labor_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->unsignedBigInteger('hourly_cost_cents');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'catalog_labor_role_org_code_unique');
            $table->index(['organization_id', 'active', 'name'], 'catalog_labor_role_org_active_idx');
        });

        Schema::table('catalog_services', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_internal_cost_cents')->nullable()->after('default_price_cents');
            $table->foreignId('default_labor_role_id')->nullable()->after('default_internal_cost_cents')
                ->constrained('catalog_labor_roles', indexName: 'catalog_service_labor_role_fk')->nullOnDelete();
        });

        Schema::table('commercial_revision_lines', function (Blueprint $table): void {
            $table->string('package_pricing_mode', 30)->nullable()->after('catalog_package_id');
            $table->string('cost_source_type', 30)->nullable()->after('cost_resolved');
            $table->unsignedBigInteger('cost_source_id')->nullable()->after('cost_source_type');
            $table->string('cost_source_name', 160)->nullable()->after('cost_source_id');
        });

        Schema::table('commercial_revision_line_components', function (Blueprint $table): void {
            $table->string('cost_source_type', 30)->nullable()->after('cost_resolved');
            $table->unsignedBigInteger('cost_source_id')->nullable()->after('cost_source_type');
            $table->string('cost_source_name', 160)->nullable()->after('cost_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_revision_line_components', function (Blueprint $table): void {
            $table->dropColumn(['cost_source_type', 'cost_source_id', 'cost_source_name']);
        });
        Schema::table('commercial_revision_lines', function (Blueprint $table): void {
            $table->dropColumn(['package_pricing_mode', 'cost_source_type', 'cost_source_id', 'cost_source_name']);
        });
        Schema::table('catalog_services', function (Blueprint $table): void {
            $table->dropForeign('catalog_service_labor_role_fk');
            $table->dropColumn(['default_internal_cost_cents', 'default_labor_role_id']);
        });
        Schema::dropIfExists('catalog_labor_roles');
    }
};
