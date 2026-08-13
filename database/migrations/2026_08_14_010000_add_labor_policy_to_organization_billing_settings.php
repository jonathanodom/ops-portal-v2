<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_billing_settings', function (Blueprint $table): void {
            $table->foreignId('default_labor_catalog_service_id')->nullable()->after('default_tax_rate_basis_points');
            $table->foreign('default_labor_catalog_service_id', 'obs_default_labor_catalog_fk')->references('id')->on('catalog_services')->restrictOnDelete();
            $table->unsignedSmallInteger('labor_billing_increment_minutes')->default(15)->after('default_labor_catalog_service_id');
            $table->string('labor_rounding_rule', 20)->default('up')->after('labor_billing_increment_minutes');
            $table->unsignedSmallInteger('minimum_billable_minutes')->default(0)->after('labor_rounding_rule');
            $table->foreignId('trip_charge_catalog_service_id')->nullable()->after('minimum_billable_minutes');
            $table->foreign('trip_charge_catalog_service_id', 'obs_trip_charge_catalog_fk')->references('id')->on('catalog_services')->restrictOnDelete();
            $table->boolean('suggest_trip_charges')->default(true)->after('trip_charge_catalog_service_id');
            $table->boolean('auto_select_trip_charges')->default(false)->after('suggest_trip_charges');
        });
    }

    public function down(): void
    {
        Schema::table('organization_billing_settings', function (Blueprint $table): void {
            $table->dropForeign('obs_trip_charge_catalog_fk');
            $table->dropForeign('obs_default_labor_catalog_fk');
            $table->dropColumn(['trip_charge_catalog_service_id', 'default_labor_catalog_service_id']);
            $table->dropColumn([
                'labor_billing_increment_minutes',
                'labor_rounding_rule',
                'minimum_billable_minutes',
                'suggest_trip_charges',
                'auto_select_trip_charges',
            ]);
        });
    }
};
