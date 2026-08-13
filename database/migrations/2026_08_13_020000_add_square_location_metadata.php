<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_provider_configurations', function (Blueprint $table): void {
            $table->string('location_name')->nullable()->after('location_id');
            $table->json('available_locations')->nullable()->after('location_name');
        });
    }

    public function down(): void
    {
        Schema::table('payment_provider_configurations', function (Blueprint $table): void {
            $table->dropColumn(['location_name', 'available_locations']);
        });
    }
};
