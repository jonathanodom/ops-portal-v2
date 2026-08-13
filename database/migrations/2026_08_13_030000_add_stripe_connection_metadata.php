<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_provider_configurations', function (Blueprint $table): void {
            $table->boolean('payments_enabled')->nullable()->after('external_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('payment_provider_configurations', function (Blueprint $table): void {
            $table->dropColumn('payments_enabled');
        });
    }
};
