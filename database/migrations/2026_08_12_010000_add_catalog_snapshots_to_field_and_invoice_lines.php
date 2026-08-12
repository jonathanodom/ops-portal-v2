<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_part_proposals', function (Blueprint $table): void {
            $this->catalogSnapshotColumns($table, 'removed_at');
            $table->index(['organization_id', 'catalog_item_type'], 'visit_proposal_catalog_type_index');
        });

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $this->catalogSnapshotColumns($table, 'override_reason');
            $table->index(['invoice_id', 'catalog_item_type'], 'invoice_line_catalog_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropIndex('invoice_line_catalog_type_index');
            $this->dropCatalogSnapshotColumns($table);
        });

        Schema::table('visit_part_proposals', function (Blueprint $table): void {
            $table->dropIndex('visit_proposal_catalog_type_index');
            $this->dropCatalogSnapshotColumns($table);
        });
    }

    private function catalogSnapshotColumns(Blueprint $table, string $after): void
    {
        $table->string('catalog_item_type', 20)->nullable()->after($after);
        $table->foreignId('catalog_service_id')->nullable()->after('catalog_item_type')->constrained()->nullOnDelete();
        $table->foreignId('catalog_service_variant_id')->nullable()->after('catalog_service_id')->constrained()->nullOnDelete();
        $table->foreignId('catalog_product_id')->nullable()->after('catalog_service_variant_id')->constrained()->nullOnDelete();
        $table->foreignId('catalog_package_id')->nullable()->after('catalog_product_id')->constrained()->nullOnDelete();
        $table->string('catalog_code_snapshot', 80)->nullable()->after('catalog_package_id');
        $table->string('catalog_name_snapshot', 160)->nullable()->after('catalog_code_snapshot');
        $table->text('catalog_description_snapshot')->nullable()->after('catalog_name_snapshot');
        $table->string('catalog_unit_code_snapshot', 80)->nullable()->after('catalog_description_snapshot');
        $table->string('catalog_unit_name_snapshot', 100)->nullable()->after('catalog_unit_code_snapshot');
        $table->unsignedBigInteger('catalog_quantity_millis')->nullable()->after('catalog_unit_name_snapshot');
        $table->unsignedBigInteger('catalog_original_unit_price_cents')->nullable()->after('catalog_quantity_millis');
        $table->unsignedBigInteger('catalog_unit_price_cents')->nullable()->after('catalog_original_unit_price_cents');
        $table->boolean('catalog_taxable')->nullable()->after('catalog_unit_price_cents');
        $table->json('catalog_package_recipe_snapshot')->nullable()->after('catalog_taxable');
        $table->foreignId('catalog_selected_by_id')->nullable()->after('catalog_package_recipe_snapshot')->constrained('users')->nullOnDelete();
        $table->timestamp('catalog_selected_at')->nullable()->after('catalog_selected_by_id');
    }

    private function dropCatalogSnapshotColumns(Blueprint $table): void
    {
        $table->dropConstrainedForeignId('catalog_selected_by_id');
        $table->dropConstrainedForeignId('catalog_package_id');
        $table->dropConstrainedForeignId('catalog_product_id');
        $table->dropConstrainedForeignId('catalog_service_variant_id');
        $table->dropConstrainedForeignId('catalog_service_id');
        $table->dropColumn([
            'catalog_item_type', 'catalog_code_snapshot', 'catalog_name_snapshot',
            'catalog_description_snapshot', 'catalog_unit_code_snapshot',
            'catalog_unit_name_snapshot', 'catalog_quantity_millis',
            'catalog_original_unit_price_cents', 'catalog_unit_price_cents',
            'catalog_taxable', 'catalog_package_recipe_snapshot', 'catalog_selected_at',
        ]);
    }
};
