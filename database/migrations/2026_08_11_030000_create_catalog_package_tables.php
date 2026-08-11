<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('catalog_categories')->restrictOnDelete();
            $table->foreignId('sales_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->string('package_code', 80);
            $table->string('name', 160);
            $table->text('customer_description')->nullable();
            $table->text('internal_description')->nullable();
            $table->string('pricing_model', 30)->default('flat');
            $table->unsignedBigInteger('default_price_cents')->nullable();
            $table->boolean('taxable')->default(true);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'package_code']);
            $table->index(['organization_id', 'active', 'name'], 'catalog_package_active_name_index');
            $table->index(['organization_id', 'category_id', 'active'], 'catalog_package_category_index');
        });

        Schema::create('catalog_package_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_package_id')->constrained()->cascadeOnDelete();
            $table->string('component_type', 20);
            $table->foreignId('catalog_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('catalog_service_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('component_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->unsignedBigInteger('quantity_millis');
            $table->unsignedSmallInteger('waste_basis_points')->default(0);
            $table->boolean('customer_visible')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('internal_notes')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['catalog_package_id', 'catalog_product_id'], 'catalog_package_product_unique');
            $table->unique(['catalog_package_id', 'catalog_service_id'], 'catalog_package_service_unique');
            $table->index(['organization_id', 'catalog_package_id', 'active'], 'catalog_package_component_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_package_components');
        Schema::dropIfExists('catalog_packages');
    }
};
