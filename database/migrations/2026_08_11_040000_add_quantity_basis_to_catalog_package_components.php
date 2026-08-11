<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_package_components', function (Blueprint $table): void {
            $table->string('quantity_basis', 30)->default('direct')->after('component_uom_id');
            $table->unsignedBigInteger('basis_count_millis')->nullable()->after('quantity_millis');
            $table->unsignedBigInteger('basis_quantity_millis')->nullable()->after('basis_count_millis');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_package_components', function (Blueprint $table): void {
            $table->dropColumn(['quantity_basis', 'basis_count_millis', 'basis_quantity_millis']);
        });
    }
};
