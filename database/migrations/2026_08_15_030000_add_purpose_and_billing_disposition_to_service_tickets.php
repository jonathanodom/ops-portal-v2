<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table): void {
            $table->string('purpose', 30)->default('service_call')->after('source');
            $table->string('billing_disposition', 20)->default('billable')->after('purpose');
            $table->index(['organization_id', 'purpose', 'billing_disposition'], 'tickets_purpose_billing_index');
        });
    }

    public function down(): void
    {
        Schema::table('service_tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_purpose_billing_index');
            $table->dropColumn(['purpose', 'billing_disposition']);
        });
    }
};
