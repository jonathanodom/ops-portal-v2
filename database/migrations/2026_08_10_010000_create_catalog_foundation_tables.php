<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('catalog_categories')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'active', 'sort_order'], 'catalog_category_active_sort_index');
        });

        Schema::create('units_of_measure', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 80);
            $table->string('symbol', 20)->nullable();
            $table->string('dimension', 30);
            $table->unsignedTinyInteger('decimal_places')->default(0);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'dimension', 'active'], 'uom_org_dimension_active_index');
        });

        Schema::create('catalog_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('catalog_categories')->restrictOnDelete();
            $table->foreignId('sales_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->string('service_code', 80);
            $table->string('name', 160);
            $table->text('customer_description')->nullable();
            $table->text('internal_description')->nullable();
            $table->text('internal_scope')->nullable();
            $table->text('internal_exclusions')->nullable();
            $table->string('pricing_model', 30);
            $table->unsignedBigInteger('default_price_cents')->nullable();
            $table->boolean('taxable')->default(false);
            $table->unsignedInteger('estimated_duration_minutes')->nullable();
            $table->boolean('customer_visible')->default(true);
            $table->boolean('requires_office_approval')->default(false);
            $table->string('billing_cadence', 20)->nullable();
            $table->unsignedSmallInteger('billing_interval')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'service_code']);
            $table->index(['organization_id', 'active', 'name'], 'catalog_service_active_name_index');
            $table->index(['organization_id', 'category_id', 'pricing_model'], 'catalog_service_filter_index');
        });

        Schema::create('catalog_service_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_service_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('label', 120);
            $table->string('customer_label', 120)->nullable();
            $table->unsignedBigInteger('price_override_cents')->nullable();
            $table->unsignedInteger('estimated_duration_minutes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['catalog_service_id', 'code']);
            $table->index(['organization_id', 'catalog_service_id', 'active'], 'catalog_variant_service_active_index');
        });

        Schema::create('catalog_service_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_service_id')->constrained('catalog_services')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['catalog_service_id', 'addon_service_id'], 'catalog_service_addon_unique');
            $table->index(['organization_id', 'catalog_service_id'], 'catalog_service_addon_org_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_service_addons');
        Schema::dropIfExists('catalog_service_variants');
        Schema::dropIfExists('catalog_services');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('catalog_categories');
    }
};
