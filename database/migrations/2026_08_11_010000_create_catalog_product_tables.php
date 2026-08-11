<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('catalog_categories')->restrictOnDelete();
            $table->foreignId('base_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignId('default_sales_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->string('product_code', 80);
            $table->string('sku', 120)->nullable();
            $table->string('name', 160);
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->text('customer_description')->nullable();
            $table->text('internal_description')->nullable();
            $table->unsignedBigInteger('sales_quantity_millis')->default(1000);
            $table->unsignedBigInteger('default_cost_cents')->nullable();
            $table->unsignedBigInteger('default_cost_quantity_millis')->default(1000);
            $table->unsignedBigInteger('default_sell_price_cents')->nullable();
            $table->boolean('taxable')->default(true);
            $table->string('tracking_type', 30)->default('standard');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'product_code']);
            $table->index(['organization_id', 'active', 'name'], 'catalog_product_active_name_index');
            $table->index(['organization_id', 'category_id', 'active'], 'catalog_product_category_index');
        });

        Schema::create('catalog_product_purchase_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->string('label', 120);
            $table->unsignedBigInteger('base_quantity_millis');
            $table->string('vendor_sku', 120)->nullable();
            $table->unsignedBigInteger('default_purchase_cost_cents')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['catalog_product_id', 'label']);
            $table->index(['organization_id', 'catalog_product_id', 'active'], 'catalog_purchase_unit_product_index');
            $table->index(['catalog_product_id', 'is_default', 'active'], 'catalog_purchase_unit_default_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_purchase_units');
        Schema::dropIfExists('catalog_products');
    }
};
